<?php

if (!class_exists('MindboxBonusPointsHistoryOperations', false)) {
    class MindboxBonusPointsHistoryOperations
    {
        private static $handlersRegistered = false;

        /**
         * No event handlers — called explicitly from any context.
         */
        public static function registerHandlers()
        {
            self::$handlersRegistered = true;
        }

        /**
         * Get customer bonus points history (Website.GetCustomerBonusPointsHistory).
         *
         * @param string $customerId Customer ID (value for mindbox_ids_key from config).
         * @param array $pageOptions {
         *     @type int    $pageNumber       Page number starting from 1 (default 1).
         *     @type int    $itemsPerPage     Items per page (default 20, max 10000).
         *     @type string $sinceDateTimeUtc Earliest action datetime UTC, YYYY-MM-DD hh:mm (optional).
         *     @type string $tillDateTimeUtc  Latest action datetime UTC, YYYY-MM-DD hh:mm (optional).
         * }
         * @return array ['success' => bool, 'data' => array|null, 'errors' => [...]]
         */
        public static function getBonusPointsHistory(string $customerId, array $pageOptions = [])
        {
            $customerId = trim($customerId);
            if ($customerId === '') {
                return ['success' => false, 'data' => null, 'errors' => ['_general' => 'Customer ID is required']];
            }

            $settings = self::settings();
            if ($settings === null) {
                return ['success' => false, 'data' => null, 'errors' => ['_general' => 'GetCustomerBonusPointsHistory operation is disabled']];
            }

            $page = [
                'pageNumber' => (int)($pageOptions['pageNumber'] ?? 1),
                'itemsPerPage' => (int)($pageOptions['itemsPerPage'] ?? 20),
            ];

            $sinceDateTimeUtc = trim((string)($pageOptions['sinceDateTimeUtc'] ?? ''));
            if ($sinceDateTimeUtc !== '') {
                $page['sinceDateTimeUtc'] = $sinceDateTimeUtc;
            }

            $tillDateTimeUtc = trim((string)($pageOptions['tillDateTimeUtc'] ?? ''));
            if ($tillDateTimeUtc !== '') {
                $page['tillDateTimeUtc'] = $tillDateTimeUtc;
            }

            $payload = [
                'page' => $page,
                'customer' => [
                    'ids' => [
                        $settings['mindbox_ids_key'] => $customerId,
                    ],
                ],
            ];

            try {
                $response = MindboxIntegration::sendSync(
                    $settings['operation'],
                    $payload,
                    null,
                    true,
                    null,
                    null,
                    false//true
                );

                return ['success' => true, 'data' => $response, 'errors' => []];
            } catch (MindboxValidationException $e) {
                $errors = self::parseErrors($e);
                return ['success' => false, 'data' => null, 'errors' => $errors];
            } catch (\Throwable $e) {
                self::log('getBonusPointsHistory failed: ' . $e->getMessage(), [
                    'customer_id' => $customerId,
                    'exception' => get_class($e),
                ]);
                return ['success' => false, 'data' => null, 'errors' => ['_general' => $e->getMessage()]];
            }
        }

        // ---- Private helpers ----

        private static function settings()
        {
            if (!class_exists('MindboxIntegrationConfig', false)) {
                return null;
            }

            $config = MindboxIntegrationConfig::get();
            if (!is_array($config)) {
                return null;
            }

            $raw = [];
            if (isset($config['operations']['getCustomerBonusPointsHistory']) && is_array($config['operations']['getCustomerBonusPointsHistory'])) {
                $raw = $config['operations']['getCustomerBonusPointsHistory'];
            }
            if ($raw === []) {
                return null;
            }

            $enabled = isset($raw['enabled']) ? (bool)$raw['enabled'] : false;
            $operation = trim((string)($raw['operation'] ?? ''));
            $mindboxIdsKey = trim((string)($raw['mindbox_ids_key'] ?? ''));

            if (!$enabled || $operation === '' || $mindboxIdsKey === '') {
                return null;
            }

            return [
                'operation' => $operation,
                'mindbox_ids_key' => $mindboxIdsKey,
            ];
        }

        private static function parseErrors(MindboxValidationException $e)
        {
            $errors = [];
            $raw = $e->getValidationMessages();

            if (is_array($raw)) {
                foreach ($raw as $item) {
                    if (is_array($item) && isset($item['message']) && (string)$item['message'] !== '') {
                        $location = isset($item['location']) ? (string)$item['location'] : '_general';
                        $errors[$location] = (string)$item['message'];
                    }
                }
            }

            if (empty($errors)) {
                $errorMessage = $e->getErrorMessage();
                $errors['_general'] = is_string($errorMessage) && $errorMessage !== ''
                    ? $errorMessage
                    : $e->getMessage();
            }

            return $errors;
        }

        private static function log($message, array $context = [])
        {
            if (!class_exists('CEventLog')) {
                return;
            }

            \CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'MINDBOX_BONUS_POINTS_OPS',
                'MODULE_ID' => 'custom',
                'ITEM_ID' => 'MindboxBonusPointsHistoryOperations',
                'DESCRIPTION' => $message . ' | context: ' . self::toJson($context),
            ]);
        }

        private static function toJson($data)
        {
            if (is_string($data)) {
                return $data;
            }

            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            return $json !== false ? $json : '';
        }
    }
}
