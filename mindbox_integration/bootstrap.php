<?php

if (defined('MINDBOX_INTEGRATION_BOOTSTRAP')) {
    return;
}

define('MINDBOX_INTEGRATION_BOOTSTRAP', true);

$requiredFiles = [
    __DIR__ . '/MindboxClient.php',
    __DIR__ . '/lib/QueueStorage.php',
    __DIR__ . '/lib/QueueService.php',
];

$missingFile = false;
foreach ($requiredFiles as $file) {
    if (!is_file($file)) {
        $missingFile = true;
        break;
    }
}

if ($missingFile) {
    if (!class_exists('MindboxIntegrationConfig', false)) {
        class MindboxIntegrationConfig
        {
            public static function get(): array
            {
                return [];
            }

            public static function withOverride(?array $override): array
            {
                return $override ?? [];
            }
        }
    }

    if (!class_exists('MindboxIntegration', false)) {
        class MindboxIntegration
        {
            public static function send(
                string $mode,
                string $operation,
                $data,
                ?string $deviceUUID = null,
                bool $authorization = false,
                ?string $transactionId = null,
                ?array $configOverride = null
            ) {
                return null;
            }

            public static function sendAsync(
                string $operation,
                $data,
                ?string $deviceUUID = null,
                bool $authorization = false,
                ?string $transactionId = null,
                ?array $configOverride = null
            ) {
                return null;
            }

            public static function sendSync(
                string $operation,
                $data,
                ?string $deviceUUID = null,
                bool $authorization = false,
                ?string $transactionId = null,
                ?array $configOverride = null
            ) {
                return null;
            }
        }
    }

    return;
}

if (!class_exists('MindboxClient', false)) {
    require_once __DIR__ . '/MindboxClient.php';
}
require_once __DIR__ . '/lib/QueueStorage.php';
require_once __DIR__ . '/lib/QueueService.php';

if (!class_exists('MindboxIntegrationConfig', false)) {
    class MindboxIntegrationConfig
    {
        private static $cached;

        public static function get(): array
        {
            if (self::$cached !== null) {
                return self::$cached;
            }

            $defaults = [
                'apiUrl' => 'api.s.mindbox.ru',
                'endpointId' => '',
                'secretKeys' => [],
                'timeout' => 5,
                'queue' => [
                    'hl_block_name' => 'MindboxQueue',
                    'retry_interval_seconds' => 900,
                    'agent_interval_seconds' => 300,
                    'batch_size' => 50,
                    'lock_seconds' => 300,
                    'log_channel' => 'mindbox',
                ],
            ];

            $configPath = __DIR__ . '/config.php';
            $config = [];
            if (is_file($configPath)) {
                $loaded = include $configPath;
                if (is_array($loaded)) {
                    $config = $loaded;
                }
            }

            self::$cached = array_replace_recursive($defaults, $config);
            return self::$cached;
        }

        public static function withOverride(?array $override): array
        {
            if ($override === null) {
                return self::get();
            }

            return array_replace_recursive(self::get(), $override);
        }
    }
}

if (!class_exists('MindboxIntegration', false)) {
    class MindboxIntegration
    {
        public static function send(
            string $mode,
            string $operation,
            $data,
            ?string $deviceUUID = null,
            bool $authorization = false,
            ?string $transactionId = null,
            ?array $configOverride = null
        ) {
            try {
                $config = MindboxIntegrationConfig::withOverride($configOverride);
                return MindboxIntegrationQueueService::sendOrQueue(
                    $mode,
                    $operation,
                    $data,
                    $config,
                    $deviceUUID,
                    $authorization,
                    $transactionId
                );
            } catch (\Throwable $e) {
                return null;
            }
        }

        public static function sendAsync(
            string $operation,
            $data,
            ?string $deviceUUID = null,
            bool $authorization = false,
            ?string $transactionId = null,
            ?array $configOverride = null
        ) {
            return self::send('async', $operation, $data, $deviceUUID, $authorization, $transactionId, $configOverride);
        }

        public static function sendSync(
            string $operation,
            $data,
            ?string $deviceUUID = null,
            bool $authorization = false,
            ?string $transactionId = null,
            ?array $configOverride = null
        ) {
            return self::send('sync', $operation, $data, $deviceUUID, $authorization, $transactionId, $configOverride);
        }
    }
}

if (!function_exists('mindbox_integration_agent')) {
    function mindbox_integration_agent()
    {
        if (class_exists('MindboxIntegrationQueueService', false)) {
            return MindboxIntegrationQueueService::agent();
        }

        return 'mindbox_integration_agent();';
    }
}
