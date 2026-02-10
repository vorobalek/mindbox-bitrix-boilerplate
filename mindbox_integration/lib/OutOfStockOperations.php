<?php

if (!class_exists('MindboxOutOfStockOperations', false)) {
    class MindboxOutOfStockOperations
    {
        private static $handlersRegistered = false;

        public static function registerHandlers()
        {
            if (self::$handlersRegistered) {
                return;
            }

            if (!function_exists('AddEventHandler')) {
                return;
            }

            $settings = self::settings();
            if ($settings === null) {
                self::$handlersRegistered = true;
                return;
            }

            AddEventHandler('iblock', 'OnAfterIBlockElementAdd', __CLASS__ . '::onAfterIBlockElementAdd');
            self::$handlersRegistered = true;
        }

        public static function onAfterIBlockElementAdd(&$fields): void
        {
            if (!is_array($fields)) {
                return;
            }

            try {
                $settings = self::settings();
                if ($settings === null) {
                    return;
                }

                if (array_key_exists('RESULT', $fields) && !$fields['RESULT']) {
                    self::debug($settings, 'skip: add result is false', $fields);
                    return;
                }

                $iblockId = (int)($fields['IBLOCK_ID'] ?? 0);
                if ($iblockId !== $settings['subscription_iblock_id']) {
                    self::debug($settings, 'skip: iblock mismatch', [
                        'actual' => $iblockId,
                        'expected' => $settings['subscription_iblock_id'],
                    ]);
                    return;
                }

                $email = trim((string)($fields[$settings['email_field']] ?? ''));
                $productId = trim((string)($fields[$settings['product_id_field']] ?? ''));

                $sent = self::sendOutOfStockWithSettings($email, $productId, $settings);
                if (!$sent) {
                    self::debug($settings, 'skip: payload validation failed', [
                        'email_field' => $settings['email_field'],
                        'product_id_field' => $settings['product_id_field'],
                        'email' => $email,
                        'product_id' => $productId,
                    ]);
                }
            } catch (\Throwable $e) {
                self::log('onAfterIBlockElementAdd failed: ' . $e->getMessage(), $fields);
            }
        }

        public static function sendOutOfStock(string $email, string $productId): bool
        {
            if (!class_exists('MindboxIntegration', false)) {
                return false;
            }

            $settings = self::settings();
            if ($settings === null) {
                return false;
            }

            return self::sendOutOfStockWithSettings($email, $productId, $settings);
        }

        private static function sendOutOfStockWithSettings(string $email, string $productId, array $settings): bool
        {
            if (!class_exists('MindboxIntegration', false)) {
                return false;
            }

            $email = trim($email);
            $productId = trim($productId);
            if (!self::isValidEmail($email) || $productId === '') {
                return false;
            }

            self::debug($settings, 'dispatch operation', [
                'operation' => $settings['operation'],
                'product_id' => $productId,
                'email' => $email,
            ]);

            MindboxIntegration::sendAsync(
                $settings['operation'],
                [
                    'customer' => [
                        'email' => $email,
                        'subscriptions' => [
                            [
                                'brand' => $settings['brand'],
                                'pointOfContact' => $settings['point_of_contact'],
                                'topic' => $settings['topic'],
                            ],
                        ],
                    ],
                    'addProductToList' => [
                        'count' => '1',
                        'product' => [
                            'ids' => [
                                $settings['product_ids_key'] => $productId,
                            ],
                        ],
                    ],
                ],
                null,
                $settings['authorization']
            );

            return true;
        }

        private static function settings(): ?array
        {
            if (!class_exists('MindboxIntegrationConfig', false)) {
                return null;
            }

            $config = MindboxIntegrationConfig::get();
            if (!is_array($config)) {
                return null;
            }

            $raw = [];
            if (isset($config['operations']['outOfStock']) && is_array($config['operations']['outOfStock'])) {
                $raw = $config['operations']['outOfStock'];
            }
            if ($raw === []) {
                return null;
            }

            $enabled = isset($raw['enabled']) ? (bool)$raw['enabled'] : false;
            $operation = trim((string)($raw['operation'] ?? ''));
            $subscriptionIblockId = (int)($raw['subscription_iblock_id'] ?? 0);
            if (!$enabled || $operation === '' || $subscriptionIblockId <= 0) {
                return null;
            }

            $productIdsKey = trim((string)($raw['product_ids_key'] ?? 'website'));
            $brand = trim((string)($raw['brand'] ?? 'podpisnie'));
            $pointOfContact = trim((string)($raw['point_of_contact'] ?? 'Email'));
            $topic = trim((string)($raw['topic'] ?? 'izdaniya'));
            $emailField = trim((string)($raw['email_field'] ?? 'NAME'));
            $productIdField = trim((string)($raw['product_id_field'] ?? 'CODE'));
            $authorization = isset($raw['authorization']) ? (bool)$raw['authorization'] : true;
            $debugLog = isset($raw['debug_log']) ? (bool)$raw['debug_log'] : false;

            if (
                $productIdsKey === ''
                || $brand === ''
                || $pointOfContact === ''
                || $topic === ''
                || $emailField === ''
                || $productIdField === ''
            ) {
                return null;
            }

            return [
                'operation' => $operation,
                'subscription_iblock_id' => $subscriptionIblockId,
                'product_ids_key' => $productIdsKey,
                'brand' => $brand,
                'point_of_contact' => $pointOfContact,
                'topic' => $topic,
                'email_field' => $emailField,
                'product_id_field' => $productIdField,
                'authorization' => $authorization,
                'debug_log' => $debugLog,
            ];
        }

        private static function isValidEmail(string $email): bool
        {
            if ($email === '') {
                return false;
            }

            if (function_exists('check_email')) {
                return (bool)check_email($email);
            }

            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }

        private static function log(string $message, array $context = []): void
        {
            if (!class_exists('CEventLog')) {
                return;
            }

            \CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'MINDBOX_OUT_OF_STOCK_OPS',
                'MODULE_ID' => 'custom',
                'ITEM_ID' => 'MindboxOutOfStockOperations',
                'DESCRIPTION' => $message . ' | context: ' . self::toJson($context),
            ]);
        }

        private static function debug(array $settings, string $message, array $context = []): void
        {
            if (empty($settings['debug_log'])) {
                return;
            }

            self::log('[debug] ' . $message, $context);
        }

        private static function toJson($data): string
        {
            if (is_string($data)) {
                return $data;
            }

            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            return $json !== false ? $json : '';
        }
    }
}
