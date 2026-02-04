<?php

use Bitrix\Main\Type\DateTime;

class MindboxIntegrationQueueService
{
    public static function sendOrQueue(
        string $mode,
        string $operation,
        $data,
        array $config,
        ?string $deviceUUID = null,
        bool $authorization = false,
        ?string $transactionId = null
    ) {
        $safeConfig = [];
        try {
            $config = self::normalizeConfig($config);
            $safeConfig = $config;
            $transactionId = $transactionId ?: self::generateTransactionId();

            $secretKey = self::resolveSecretKey($config, $config['endpointId']);

            $client = MindboxClientFactory::getClient(
                $config['apiUrl'],
                $config['endpointId'],
                $secretKey,
                $config['timeout']
            );

            if ($client === null) {
                self::logManual($config, 'client initialization failed', $operation, $data, null);
                self::storeFailed($config, $mode, $operation, $data, $deviceUUID, $authorization, $transactionId, null);
                return null;
            }

            try {
                $method = $mode === 'async' ? 'executeAsyncOperation' : 'executeSyncOperation';
                return $client->$method($operation, $data, $deviceUUID, $authorization, $transactionId);
            } catch (MindboxTransactionAlreadyProcessedException $e) {
                return null;
            } catch (MindboxInternalServerException $e) {
                self::enqueueRetry($config, $mode, $operation, $data, $deviceUUID, $authorization, $transactionId, $e);
                return null;
            } catch (MindboxTransportException $e) {
                self::enqueueRetry($config, $mode, $operation, $data, $deviceUUID, $authorization, $transactionId, $e);
                return null;
            } catch (MindboxClientException $e) {
                if (self::isRetryableHttp($e)) {
                    self::enqueueRetry($config, $mode, $operation, $data, $deviceUUID, $authorization, $transactionId, $e);
                    return null;
                }

                self::logManual($config, self::formatException($e), $operation, $data, $transactionId);
                self::storeFailed($config, $mode, $operation, $data, $deviceUUID, $authorization, $transactionId, $e);
                return null;
            } catch (\Throwable $e) {
                self::logManual($config, $e->getMessage(), $operation, $data, $transactionId);
                self::storeFailed($config, $mode, $operation, $data, $deviceUUID, $authorization, $transactionId, null, $e);
                return null;
            }
        } catch (\Throwable $e) {
            self::logManual($safeConfig, $e->getMessage(), $operation ?: 'unknown', $data, $transactionId);
            return null;
        }
    }

    public static function agent(): string
    {
        $config = MindboxIntegrationConfig::get();
        $queue = self::queueConfig($config);

        $now = new DateTime();
        try {
            $rows = MindboxIntegrationQueueStorage::getDue(
                $queue['hl_block_name'],
                (int)$queue['batch_size'],
                $now,
                (int)$queue['lock_seconds']
            );
        } catch (\Throwable $e) {
            self::logManual($config, $e->getMessage(), 'queue-agent', [], null);
            return 'mindbox_integration_agent();';
        }

        foreach ($rows as $row) {
            if (!self::lockRow($queue['hl_block_name'], (int)$row['ID'], (int)$queue['lock_seconds'], $config)) {
                continue;
            }

            $requestConfig = [
                'apiUrl' => (string)$row['UF_API_URL'],
                'endpointId' => (string)$row['UF_ENDPOINT_ID'],
                'secretKey' => self::resolveSecretKey($config, (string)$row['UF_ENDPOINT_ID']),
                'timeout' => (int)$row['UF_TIMEOUT'],
                'queue' => $queue,
            ];

            $transactionId = $row['UF_TRANSACTION_ID'] ? (string)$row['UF_TRANSACTION_ID'] : null;

            try {
                $client = MindboxClientFactory::getClient(
                    $requestConfig['apiUrl'],
                    $requestConfig['endpointId'],
                    $requestConfig['secretKey'],
                    $requestConfig['timeout']
                );

                if ($client === null) {
                    throw new \RuntimeException('client initialization failed');
                }

                $method = $row['UF_MODE'] === 'async' ? 'executeAsyncOperation' : 'executeSyncOperation';
                $client->$method(
                    (string)$row['UF_OPERATION'],
                    (string)$row['UF_DATA'],
                    $row['UF_DEVICE_UUID'] ? (string)$row['UF_DEVICE_UUID'] : null,
                    (bool)$row['UF_AUTH'],
                    $transactionId
                );

                self::markSuccess($queue['hl_block_name'], (int)$row['ID'], $config);
            } catch (MindboxTransactionAlreadyProcessedException $e) {
                self::markSuccess($queue['hl_block_name'], (int)$row['ID'], $config);
            } catch (MindboxInternalServerException $e) {
                self::scheduleRetry($queue['hl_block_name'], (int)$row['ID'], $queue, $config, $e, (int)$row['UF_TRIES']);
            } catch (MindboxTransportException $e) {
                self::scheduleRetry($queue['hl_block_name'], (int)$row['ID'], $queue, $config, $e, (int)$row['UF_TRIES']);
            } catch (MindboxClientException $e) {
                if (self::isRetryableHttp($e)) {
                    self::scheduleRetry($queue['hl_block_name'], (int)$row['ID'], $queue, $config, $e, (int)$row['UF_TRIES']);
                } else {
                    self::markFailed($queue['hl_block_name'], (int)$row['ID'], $queue, $config, (int)$row['UF_TRIES'], $e);
                    self::logManual($requestConfig, self::formatException($e), (string)$row['UF_OPERATION'], (string)$row['UF_DATA'], $transactionId);
                }
            } catch (\Throwable $e) {
                self::markFailed($queue['hl_block_name'], (int)$row['ID'], $queue, $config, (int)$row['UF_TRIES'], null, $e);
                self::logManual($requestConfig, $e->getMessage(), (string)$row['UF_OPERATION'], (string)$row['UF_DATA'], $transactionId);
            }
        }

        return 'mindbox_integration_agent();';
    }

    private static function normalizeConfig(array $config): array
    {
        if (!isset($config['queue']) || !is_array($config['queue'])) {
            $config['queue'] = [];
        }
        $config['queue'] = self::queueConfig($config);

        $config['apiUrl'] = trim((string)($config['apiUrl'] ?? ''));
        $config['endpointId'] = trim((string)($config['endpointId'] ?? ''));
        if (!isset($config['secretKeys']) || !is_array($config['secretKeys'])) {
            $config['secretKeys'] = [];
        }
        if (isset($config['secretKey']) && $config['secretKey'] !== '') {
            $config['secretKeys'][$config['endpointId']] = (string)$config['secretKey'];
        }
        $config['timeout'] = (int)($config['timeout'] ?? 5);

        return $config;
    }

    private static function queueConfig(array $config): array
    {
        $queue = $config['queue'] ?? [];
        return [
            'hl_block_name' => (string)($queue['hl_block_name'] ?? 'MindboxQueue'),
            'retry_interval_seconds' => (int)($queue['retry_interval_seconds'] ?? 900),
            'agent_interval_seconds' => (int)($queue['agent_interval_seconds'] ?? 300),
            'batch_size' => (int)($queue['batch_size'] ?? 50),
            'lock_seconds' => (int)($queue['lock_seconds'] ?? 300),
            'log_channel' => (string)($queue['log_channel'] ?? 'mindbox'),
        ];
    }

    private static function safeAdd(array $config, string $hlBlockName, array $fields): bool
    {
        try {
            MindboxIntegrationQueueStorage::add($hlBlockName, $fields);
            return true;
        } catch (\Throwable $e) {
            $transactionId = isset($fields['UF_TRANSACTION_ID']) ? (string)$fields['UF_TRANSACTION_ID'] : null;
            self::logManual($config, 'queue add failed: ' . $e->getMessage(), 'queue', $fields, $transactionId);
            return false;
        }
    }

    private static function safeUpdate(array $config, string $hlBlockName, int $id, array $fields): bool
    {
        try {
            MindboxIntegrationQueueStorage::updateById($hlBlockName, $id, $fields);
            return true;
        } catch (\Throwable $e) {
            $transactionId = isset($fields['UF_TRANSACTION_ID']) ? (string)$fields['UF_TRANSACTION_ID'] : null;
            self::logManual($config, 'queue update failed: ' . $e->getMessage(), 'queue', $fields, $transactionId);
            return false;
        }
    }

    private static function enqueueRetry(
        array $config,
        string $mode,
        string $operation,
        $data,
        ?string $deviceUUID,
        bool $authorization,
        string $transactionId,
        ?MindboxClientException $e = null
    ): void {
        $queue = self::queueConfig($config);
        $now = new DateTime();
        $nextRun = DateTime::createFromTimestamp(time() + (int)$queue['retry_interval_seconds']);

        self::safeAdd($config, $queue['hl_block_name'], [
            'UF_STATUS' => 'R',
            'UF_NEXT_RUN' => $nextRun,
            'UF_TRIES' => 1,
            'UF_MODE' => $mode,
            'UF_OPERATION' => $operation,
            'UF_DATA' => self::normalizeData($data),
            'UF_DEVICE_UUID' => $deviceUUID,
            'UF_AUTH' => $authorization ? 1 : 0,
            'UF_API_URL' => $config['apiUrl'],
            'UF_ENDPOINT_ID' => $config['endpointId'],
            'UF_TIMEOUT' => (int)$config['timeout'],
            'UF_TRANSACTION_ID' => $transactionId,
            'UF_HTTP_STATUS' => $e ? $e->getHttpStatus() : 0,
            'UF_RESPONSE_STATUS' => $e ? $e->getResponseStatus() : null,
            'UF_ERROR_ID' => $e ? $e->getErrorId() : null,
            'UF_ERROR_MESSAGE' => $e ? $e->getErrorMessage() : null,
            'UF_CREATED_AT' => $now,
            'UF_UPDATED_AT' => $now,
            'UF_LAST_ERROR_AT' => $now,
        ]);
    }

    private static function storeFailed(
        array $config,
        string $mode,
        string $operation,
        $data,
        ?string $deviceUUID,
        bool $authorization,
        string $transactionId,
        ?MindboxClientException $e = null,
        ?\Throwable $t = null
    ): void {
        $queue = self::queueConfig($config);
        $now = new DateTime();

        self::safeAdd($config, $queue['hl_block_name'], [
            'UF_STATUS' => 'F',
            'UF_NEXT_RUN' => null,
            'UF_TRIES' => 1,
            'UF_MODE' => $mode,
            'UF_OPERATION' => $operation,
            'UF_DATA' => self::normalizeData($data),
            'UF_DEVICE_UUID' => $deviceUUID,
            'UF_AUTH' => $authorization ? 1 : 0,
            'UF_API_URL' => $config['apiUrl'],
            'UF_ENDPOINT_ID' => $config['endpointId'],
            'UF_TIMEOUT' => (int)$config['timeout'],
            'UF_TRANSACTION_ID' => $transactionId,
            'UF_HTTP_STATUS' => $e ? $e->getHttpStatus() : 0,
            'UF_RESPONSE_STATUS' => $e ? $e->getResponseStatus() : null,
            'UF_ERROR_ID' => $e ? $e->getErrorId() : null,
            'UF_ERROR_MESSAGE' => $e ? $e->getErrorMessage() : ($t ? $t->getMessage() : null),
            'UF_CREATED_AT' => $now,
            'UF_UPDATED_AT' => $now,
            'UF_LAST_ERROR_AT' => $now,
        ]);
    }

    private static function lockRow(string $hlBlockName, int $id, int $lockSeconds, array $config): bool
    {
        $lockedUntil = DateTime::createFromTimestamp(time() + $lockSeconds);
        return self::safeUpdate($config, $hlBlockName, $id, [
            'UF_STATUS' => 'W',
            'UF_LOCKED_UNTIL' => $lockedUntil,
            'UF_UPDATED_AT' => new DateTime(),
        ]);
    }

    private static function markSuccess(string $hlBlockName, int $id, array $config): void
    {
        self::safeUpdate($config, $hlBlockName, $id, [
            'UF_STATUS' => 'S',
            'UF_UPDATED_AT' => new DateTime(),
            'UF_LOCKED_UNTIL' => null,
        ]);
    }

    private static function scheduleRetry(
        string $hlBlockName,
        int $id,
        array $queue,
        array $config,
        MindboxClientException $e,
        int $currentTries
    ): void
    {
        $nextRun = DateTime::createFromTimestamp(time() + (int)$queue['retry_interval_seconds']);
        self::safeUpdate($config, $hlBlockName, $id, [
            'UF_STATUS' => 'R',
            'UF_TRIES' => $currentTries + 1,
            'UF_NEXT_RUN' => $nextRun,
            'UF_HTTP_STATUS' => $e->getHttpStatus(),
            'UF_RESPONSE_STATUS' => $e->getResponseStatus(),
            'UF_ERROR_ID' => $e->getErrorId(),
            'UF_ERROR_MESSAGE' => $e->getErrorMessage(),
            'UF_UPDATED_AT' => new DateTime(),
            'UF_LAST_ERROR_AT' => new DateTime(),
            'UF_LOCKED_UNTIL' => null,
        ]);
    }

    private static function markFailed(
        string $hlBlockName,
        int $id,
        array $queue,
        array $config,
        int $currentTries,
        ?MindboxClientException $e = null,
        ?\Throwable $t = null
    ): void {
        self::safeUpdate($config, $hlBlockName, $id, [
            'UF_STATUS' => 'F',
            'UF_TRIES' => $currentTries + 1,
            'UF_HTTP_STATUS' => $e ? $e->getHttpStatus() : 0,
            'UF_RESPONSE_STATUS' => $e ? $e->getResponseStatus() : null,
            'UF_ERROR_ID' => $e ? $e->getErrorId() : null,
            'UF_ERROR_MESSAGE' => $e ? $e->getErrorMessage() : ($t ? $t->getMessage() : null),
            'UF_UPDATED_AT' => new DateTime(),
            'UF_LAST_ERROR_AT' => new DateTime(),
            'UF_LOCKED_UNTIL' => null,
        ]);
    }

    private static function normalizeData($data): string
    {
        if (is_string($data)) {
            return $data;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return $json !== false ? $json : '';
    }

    private static function isRetryableHttp(MindboxClientException $e): bool
    {
        if ($e instanceof MindboxTransportException) {
            return true;
        }
        if ($e instanceof MindboxInternalServerException) {
            return true;
        }

        $code = $e->getHttpStatus();
        if (in_array($code, [500, 502, 503, 504], true)) {
            return true;
        }

        return false;
    }

    private static function generateTransactionId(): string
    {
        if (function_exists('random_bytes')) {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }

        return uniqid('mindbox-', true);
    }

    private static function resolveSecretKey(array $config, string $endpointId): string
    {
        if (!isset($config['secretKeys']) || !is_array($config['secretKeys'])) {
            return '';
        }

        if (isset($config['secretKeys'][$endpointId])) {
            return (string)$config['secretKeys'][$endpointId];
        }

        return '';
    }

    private static function formatException(MindboxClientException $e): string
    {
        $parts = [
            'http' => $e->getHttpStatus(),
            'status' => $e->getResponseStatus(),
            'errorId' => $e->getErrorId(),
            'message' => $e->getErrorMessage(),
        ];
        return trim($e->getMessage() . ' ' . json_encode($parts, JSON_UNESCAPED_UNICODE));
    }

    private static function logManual(array $config, string $message, string $operation, $data, ?string $transactionId): void
    {
        if (!class_exists('CEventLog')) {
            return;
        }

        $description = $message . ' | operation: ' . $operation;
        if ($transactionId !== null && $transactionId !== '') {
            $description .= ' | transactionId: ' . $transactionId;
        }
        $description .= ' | data: ' . self::normalizeData($data);

        \CEventLog::Add([
            'SEVERITY' => 'ERROR',
            'AUDIT_TYPE_ID' => 'MINDBOX_QUEUE',
            'MODULE_ID' => 'custom',
            'ITEM_ID' => $operation,
            'DESCRIPTION' => $description,
        ]);
    }
}
