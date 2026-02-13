<?php

if (!class_exists('MindboxGetByCardOperations', false)) {
    class MindboxGetByCardOperations
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
         * Get customer info by discount card number (Website.GetByCard).
         *
         * @param string $cardNumber Discount card number.
         * @return array ['success' => bool, 'data' => array|null, 'errors' => [...]]
         */
        public static function getByCard(string $cardNumber)
        {
            $cardNumber = trim($cardNumber);
            if ($cardNumber === '') {
                return ['success' => false, 'data' => null, 'errors' => ['_general' => 'Card number is required']];
            }

            $settings = self::settings();
            if ($settings === null) {
                return ['success' => false, 'data' => null, 'errors' => ['_general' => 'GetByCard operation is disabled']];
            }

            $payload = [
                'customer' => [
                    'discountCard' => [
                        'ids' => [
                            $settings['discount_card_ids_key'] => $cardNumber,
                        ],
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
                self::log('getByCard failed: ' . $e->getMessage(), [
                    'card_number' => $cardNumber,
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
            if (isset($config['operations']['getByCard']) && is_array($config['operations']['getByCard'])) {
                $raw = $config['operations']['getByCard'];
            }
            if ($raw === []) {
                return null;
            }

            $enabled = isset($raw['enabled']) ? (bool)$raw['enabled'] : false;
            $operation = trim((string)($raw['operation'] ?? ''));
            $discountCardIdsKey = trim((string)($raw['discount_card_ids_key'] ?? ''));

            if (!$enabled || $operation === '' || $discountCardIdsKey === '') {
                return null;
            }

            return [
                'operation' => $operation,
                'discount_card_ids_key' => $discountCardIdsKey,
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
                'AUDIT_TYPE_ID' => 'MINDBOX_GET_BY_CARD_OPS',
                'MODULE_ID' => 'custom',
                'ITEM_ID' => 'MindboxGetByCardOperations',
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
