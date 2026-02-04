<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;

global $USER;

if (!$USER || !$USER->IsAdmin()) {
    echo 'Access denied';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

$messages = [];
$errors = [];

$config = [];
$configPath = __DIR__ . '/config.php';
if (is_file($configPath)) {
    $loaded = include $configPath;
    if (is_array($loaded)) {
        $config = $loaded;
    }
}

$hlName = $config['queue']['hl_block_name'] ?? 'MindboxQueue';
$tableName = 'mindbox_queue';
$agentInterval = (int)($config['queue']['agent_interval_seconds'] ?? 300);
if ($agentInterval < 60) {
    $agentInterval = 60;
}

if (!Loader::includeModule('highloadblock')) {
    $errors[] = 'highloadblock module is not available';
}

$hlId = null;
$created = false;

if (empty($errors)) {
    $existing = HighloadBlockTable::getList([
        'filter' => ['=NAME' => $hlName],
    ])->fetch();

    if ($existing) {
        if (!empty($existing['TABLE_NAME']) && $existing['TABLE_NAME'] !== $tableName) {
            $errors[] = 'HL block name exists with different table name: ' . $existing['TABLE_NAME'];
        }
        $hlId = (int)$existing['ID'];
        $messages[] = 'HL block already exists: ' . $hlName . ' (ID ' . $hlId . ')';
    } else {
        $addResult = HighloadBlockTable::add([
            'NAME' => $hlName,
            'TABLE_NAME' => $tableName,
        ]);

        if ($addResult->isSuccess()) {
            $hlId = (int)$addResult->getId();
            $created = true;
            $messages[] = 'HL block created: ' . $hlName . ' (ID ' . $hlId . ')';
        } else {
            $errors[] = 'Failed to create HL block: ' . implode('; ', $addResult->getErrorMessages());
        }
    }
}

if ($hlId && empty($errors)) {
    $entityId = 'HLBLOCK_' . $hlId;
    if (!class_exists('CUserTypeEntity')) {
        $errors[] = 'CUserTypeEntity is not available';
    } else {
        $userType = new CUserTypeEntity();
    }

    $fields = [
        'UF_STATUS' => ['USER_TYPE_ID' => 'string', 'DEFAULT_VALUE' => 'N'],
        'UF_NEXT_RUN' => ['USER_TYPE_ID' => 'datetime'],
        'UF_TRIES' => ['USER_TYPE_ID' => 'integer', 'DEFAULT_VALUE' => 0],
        'UF_MODE' => ['USER_TYPE_ID' => 'string'],
        'UF_OPERATION' => ['USER_TYPE_ID' => 'string'],
        'UF_DATA' => ['USER_TYPE_ID' => 'string', 'SETTINGS' => ['ROWS' => 6, 'SIZE' => 50]],
        'UF_DEVICE_UUID' => ['USER_TYPE_ID' => 'string'],
        'UF_AUTH' => ['USER_TYPE_ID' => 'boolean', 'SETTINGS' => ['DEFAULT_VALUE' => 0, 'DISPLAY' => 'CHECKBOX']],
        'UF_API_URL' => ['USER_TYPE_ID' => 'string'],
        'UF_ENDPOINT_ID' => ['USER_TYPE_ID' => 'string'],
        'UF_TIMEOUT' => ['USER_TYPE_ID' => 'integer', 'DEFAULT_VALUE' => 5],
        'UF_TRANSACTION_ID' => ['USER_TYPE_ID' => 'string'],
        'UF_HTTP_STATUS' => ['USER_TYPE_ID' => 'integer'],
        'UF_RESPONSE_STATUS' => ['USER_TYPE_ID' => 'string'],
        'UF_ERROR_ID' => ['USER_TYPE_ID' => 'string'],
        'UF_ERROR_MESSAGE' => ['USER_TYPE_ID' => 'string', 'SETTINGS' => ['ROWS' => 4, 'SIZE' => 50]],
        'UF_CREATED_AT' => ['USER_TYPE_ID' => 'datetime'],
        'UF_UPDATED_AT' => ['USER_TYPE_ID' => 'datetime'],
        'UF_LAST_ERROR_AT' => ['USER_TYPE_ID' => 'datetime'],
        'UF_LOCKED_UNTIL' => ['USER_TYPE_ID' => 'datetime'],
    ];

    foreach ($fields as $fieldName => $field) {
        if (!empty($errors)) {
            break;
        }
        $existingField = CUserTypeEntity::GetList([], [
            'ENTITY_ID' => $entityId,
            'FIELD_NAME' => $fieldName,
        ])->Fetch();

        if ($existingField) {
            continue;
        }

        $addField = [
            'ENTITY_ID' => $entityId,
            'FIELD_NAME' => $fieldName,
            'USER_TYPE_ID' => $field['USER_TYPE_ID'],
            'MULTIPLE' => 'N',
            'MANDATORY' => 'N',
            'SORT' => 100,
            'EDIT_FORM_LABEL' => ['en' => $fieldName],
            'LIST_COLUMN_LABEL' => ['en' => $fieldName],
            'LIST_FILTER_LABEL' => ['en' => $fieldName],
        ];

        if (isset($field['DEFAULT_VALUE'])) {
            $addField['DEFAULT_VALUE'] = $field['DEFAULT_VALUE'];
        }

        if (isset($field['SETTINGS'])) {
            $addField['SETTINGS'] = $field['SETTINGS'];
        }

        $fieldId = $userType->Add($addField);
        if (!$fieldId) {
            $errors[] = 'Failed to create field: ' . $fieldName;
        }
    }

    if (empty($errors)) {
        $agentName = 'mindbox_integration_agent();';
        if (class_exists('CAgent')) {
            $agent = CAgent::GetList([], ['NAME' => $agentName])->Fetch();
            if (!$agent) {
                CAgent::AddAgent($agentName, '', 'N', $agentInterval);
                $messages[] = 'Agent registered: ' . $agentName . ' (interval ' . $agentInterval . 's)';
            } else {
                $messages[] = 'Agent already exists: ' . $agentName;
            }
        } else {
            $errors[] = 'CAgent is not available';
        }

        if (empty($errors)) {
            COption::SetOptionString('mindbox_integration', 'HL_BLOCK_ID', (string)$hlId);
            COption::SetOptionString('mindbox_integration', 'HL_BLOCK_CREATED', $created ? 'Y' : 'N');
            COption::SetOptionString('mindbox_integration', 'AGENT_NAME', $agentName);
        }
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if (!empty($errors)) {
    echo '<div style="color:#b00">' . implode('<br>', $errors) . '</div>';
}
if (!empty($messages)) {
    echo '<div style="color:#060">' . implode('<br>', $messages) . '</div>';
}
if (empty($errors)) {
    echo '<div>Install completed.</div>';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
