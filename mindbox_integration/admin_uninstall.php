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

$agentName = COption::GetOptionString('mindbox_integration', 'AGENT_NAME', 'mindbox_integration_agent();');
$hlId = (int)COption::GetOptionString('mindbox_integration', 'HL_BLOCK_ID', '0');
$created = COption::GetOptionString('mindbox_integration', 'HL_BLOCK_CREATED', 'N');
$forceDelete = isset($_REQUEST['force']) && $_REQUEST['force'] === 'Y';

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

if (class_exists('CAgent')) {
    $agentNames = array_unique([$agentName, '\\MindboxIntegrationQueueService::agent();']);
    foreach ($agentNames as $name) {
        $agent = CAgent::GetList([], ['NAME' => $name]);
        while ($row = $agent->Fetch()) {
            CAgent::Delete((int)$row['ID']);
        }
    }
    $messages[] = 'Agents removed';
}

if (!Loader::includeModule('highloadblock')) {
    $errors[] = 'highloadblock module is not available';
} else {
    $hlFromName = false;
    if ($hlId <= 0) {
        $existing = HighloadBlockTable::getList([
            'filter' => ['=NAME' => $hlName],
        ])->fetch();
        if ($existing) {
            $hlId = (int)$existing['ID'];
            $hlFromName = true;
        }
    }

    $canDelete = false;
    if ($hlId > 0) {
        if ($created === 'Y') {
            $canDelete = true;
        } elseif ($hlFromName) {
            $hl = HighloadBlockTable::getById($hlId)->fetch();
            if ($hl && $hl['NAME'] === $hlName && $hl['TABLE_NAME'] === $tableName) {
                $canDelete = true;
            }
        }
        if (!$canDelete && $forceDelete) {
            $canDelete = true;
        }
    }

    if ($canDelete && $hlId > 0) {
        $deleteResult = HighloadBlockTable::delete($hlId);
        if ($deleteResult->isSuccess()) {
            $messages[] = 'HL block deleted (ID ' . $hlId . ')';
        } else {
            $errors[] = 'Failed to delete HL block: ' . implode('; ', $deleteResult->getErrorMessages());
        }
    } elseif ($hlId > 0) {
        $messages[] = 'HL block not deleted (not created by installer)';
    } else {
        $messages[] = 'HL block not found';
    }
}

COption::RemoveOption('mindbox_integration');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if (!empty($errors)) {
    echo '<div style="color:#b00">' . implode('<br>', $errors) . '</div>';
}
if (!empty($messages)) {
    echo '<div style="color:#060">' . implode('<br>', $messages) . '</div>';
}
if (empty($errors)) {
    echo '<div>Uninstall completed.</div>';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
