<?php

if (!class_exists('MindboxRegisterCustomerOperations', false)) {
    class MindboxRegisterCustomerOperations
    {
        private static $handlersRegistered = false;

        /**
         * Bitrix field -> Mindbox customer field mapping.
         */
        private static $fieldMap = [
            'NAME' => 'firstName',
            'LAST_NAME' => 'lastName',
            'EMAIL' => 'email',
            'PERSONAL_PHONE' => 'mobilePhone',
        ];

        /**
         * Reverse mapping: Mindbox location keyword -> Bitrix field.
         * Used to parse validation errors.
         */
        private static $locationMap = [
            'firstName' => 'NAME',
            'lastName' => 'LAST_NAME',
            'email' => 'EMAIL',
            'mobilePhone' => 'PERSONAL_PHONE',
        ];

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

            AddEventHandler('main', 'OnAfterUserRegister', __CLASS__ . '::onAfterUserRegister');
            self::$handlersRegistered = true;
        }

        /**
         * OnAfterUserRegister handler.
         *
         * Sends RegisterCustomer to Mindbox with Email+SMS subscriptions.
         * Never blocks user registration — all errors are caught and logged.
         *
         * @param array $arFields Registration result fields.
         */
        public static function onAfterUserRegister($arFields)
        {
            if (!is_array($arFields)) {
                return;
            }

            $userId = (int)($arFields['USER_ID'] ?? 0);
            if ($userId <= 0) {
                return;
            }

            try {
                self::sendRegisterCustomer($userId, [
                    'subscribeEmail' => true,
                    'subscribeSms' => true,
                    'discountCardId' => null,
                ]);
            } catch (\Throwable $e) {
                self::log('onAfterUserRegister failed: ' . $e->getMessage(), [
                    'user_id' => $userId,
                    'exception' => get_class($e),
                ]);
            }
        }

        /**
         * Public API: send RegisterCustomer for a given user.
         *
         * Can be called from custom controllers, API handlers, etc.
         * When $userId is 0, customer data must be provided via $options
         * (firstName, lastName, email, mobilePhone) and customer.ids will be omitted.
         *
         * @param int $userId Bitrix user ID (0 for anonymous registration).
         * @param array $options {
         *     @type bool        $subscribeEmail  Subscribe to Email (default true).
         *     @type bool        $subscribeSms    Subscribe to SMS (default true).
         *     @type string|null $discountCardId  Discount card number (default null).
         *     @type string      $firstName       Customer first name (when userId = 0).
         *     @type string      $lastName        Customer last name (when userId = 0).
         *     @type string      $email           Customer email (when userId = 0).
         *     @type string      $mobilePhone     Customer phone (when userId = 0).
         * }
         * @return array ['success' => bool, 'errors' => [field => message, ...]]
         */
        public static function sendRegisterCustomer(int $userId, array $options = [])
        {
            $settings = self::settings();
            if ($settings === null) {
                return ['success' => false, 'errors' => ['_general' => 'RegisterCustomer operation is disabled']];
            }

            if ($userId > 0) {
                $currentUser = self::loadUser($userId);
                if ($currentUser === null) {
                    return ['success' => false, 'errors' => ['_general' => 'User not found']];
                }
            } else {
                // Anonymous mode: build user array from options
                $currentUser = [
                    'NAME' => (string)($options['firstName'] ?? ''),
                    'LAST_NAME' => (string)($options['lastName'] ?? ''),
                    'EMAIL' => (string)($options['email'] ?? ''),
                    'PERSONAL_PHONE' => (string)($options['mobilePhone'] ?? ''),
                ];
            }

            $payload = self::buildPayload($currentUser, $settings, $options);
            if ($payload === null) {
                return ['success' => false, 'errors' => ['_general' => 'Cannot build payload: missing required fields']];
            }

            try {
                MindboxIntegration::sendSync(
                    $settings['operation'],
                    $payload,
                    null,
                    true,
                    null,
                    null,
                    false//true
                );

                return ['success' => true, 'errors' => []];
            } catch (MindboxValidationException $e) {
                $errors = self::parseValidationErrors($e, $settings);
                return ['success' => false, 'errors' => $errors];
            } catch (\Throwable $e) {
                $logContext = ['exception' => get_class($e)];
                if ($userId > 0) {
                    $logContext['user_id'] = $userId;
                } else {
                    $logContext['email'] = $options['email'] ?? '';
                }
                self::log('sendRegisterCustomer failed: ' . $e->getMessage(), $logContext);
                return ['success' => true, 'errors' => []];
            }
        }

        // ---- Private helpers ----

        /**
         * Build the Mindbox API payload from user data.
         *
         * @param array $user     Bitrix user data.
         * @param array $settings Operation settings.
         * @param array $options  Subscription and card options.
         * @return array|null Payload array or null if required fields are missing.
         */
        private static function buildPayload(array $user, array $settings, array $options)
        {
            $subscribeEmail = isset($options['subscribeEmail']) ? (bool)$options['subscribeEmail'] : true;
            $subscribeSms = isset($options['subscribeSms']) ? (bool)$options['subscribeSms'] : true;
            $discountCardId = isset($options['discountCardId']) ? (string)$options['discountCardId'] : '';

            $customer = [];

            // Standard fields
            foreach (self::$fieldMap as $bitrixField => $mindboxField) {
                $value = trim((string)($user[$bitrixField] ?? ''));
                if ($value === '') {
                    continue;
                }

                $customer[$mindboxField] = $value;
            }

            if (empty($customer)) {
                return null;
            }

            // IDs (optional — omitted for anonymous registration)
            $siteCustomerId = self::resolveSiteCustomerId($user, $settings['site_customer_id_field']);
            if ($siteCustomerId !== '') {
                $customer['ids'] = [
                    $settings['mindbox_ids_key'] => $siteCustomerId,
                ];
            }

            // Discount card (optional)
            if ($discountCardId !== '' && $settings['discount_card_ids_key'] !== '') {
                $customer['discountCard'] = [
                    'ids' => [
                        $settings['discount_card_ids_key'] => $discountCardId,
                    ],
                ];
            }

            // Subscriptions (optional)
            $subscriptions = [];
            $brand = $settings['brand'];
            $topic = $settings['topic'];

            if ($subscribeEmail && $brand !== '' && $topic !== '') {
                $subscriptions[] = [
                    'brand' => $brand,
                    'pointOfContact' => 'Email',
                    'topic' => $topic,
                ];
            }

            if ($subscribeSms && $brand !== '' && $topic !== '') {
                $subscriptions[] = [
                    'brand' => $brand,
                    'pointOfContact' => 'SMS',
                    'topic' => $topic,
                ];
            }

            if (!empty($subscriptions)) {
                $customer['subscriptions'] = $subscriptions;
            }

            return ['customer' => $customer];
        }

        /**
         * Parse Mindbox validation errors into Bitrix field -> message map.
         *
         * @param MindboxValidationException $e
         * @param array $settings
         * @return array ['BITRIX_FIELD' => 'message', ...]
         */
        private static function parseValidationErrors(MindboxValidationException $e, array $settings)
        {
            $errors = [];
            $raw = $e->getValidationMessages();

            if (!is_array($raw)) {
                $errorMessage = $e->getErrorMessage();
                if (is_string($errorMessage) && $errorMessage !== '') {
                    $errors['_general'] = $errorMessage;
                } else {
                    $errors['_general'] = $e->getMessage();
                }
                return $errors;
            }

            foreach ($raw as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $message = isset($item['message']) ? (string)$item['message'] : '';
                $location = isset($item['location']) ? (string)$item['location'] : '';

                if ($message === '') {
                    continue;
                }

                $bitrixField = self::mapLocationToBitrixField($location, $settings);
                $errors[$bitrixField] = $message;
            }

            if (empty($errors)) {
                $errorMessage = $e->getErrorMessage();
                $errors['_general'] = is_string($errorMessage) && $errorMessage !== ''
                    ? $errorMessage
                    : $e->getMessage();
            }

            return $errors;
        }

        /**
         * Map a Mindbox validation location string to a Bitrix field name.
         *
         * @param string $location e.g. "customer.email", "customer.mobilePhone"
         * @param array $settings
         * @return string Bitrix field name or '_general'
         */
        private static function mapLocationToBitrixField($location, array $settings)
        {
            if ($location === '') {
                return '_general';
            }

            foreach (self::$locationMap as $mindboxKeyword => $bitrixField) {
                if ($bitrixField !== null && strpos($location, $mindboxKeyword) !== false) {
                    return $bitrixField;
                }
            }

            return '_general';
        }

        /**
         * Load and validate operation settings from config.
         *
         * @return array|null Settings array or null if disabled/invalid.
         */
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
            if (isset($config['operations']['registerCustomer']) && is_array($config['operations']['registerCustomer'])) {
                $raw = $config['operations']['registerCustomer'];
            }
            if ($raw === []) {
                return null;
            }

            $enabled = isset($raw['enabled']) ? (bool)$raw['enabled'] : false;
            $operation = trim((string)($raw['operation'] ?? ''));
            $mindboxIdsKey = trim((string)($raw['mindbox_ids_key'] ?? ''));
            $siteCustomerIdField = trim((string)($raw['site_customer_id_field'] ?? 'ID'));

            if (!$enabled || $operation === '' || $mindboxIdsKey === '') {
                return null;
            }

            $discountCardIdsKey = trim((string)($raw['discount_card_ids_key'] ?? ''));
            $brand = trim((string)($raw['brand'] ?? ''));
            $topic = trim((string)($raw['topic'] ?? ''));

            return [
                'operation' => $operation,
                'mindbox_ids_key' => $mindboxIdsKey,
                'site_customer_id_field' => $siteCustomerIdField,
                'discount_card_ids_key' => $discountCardIdsKey,
                'brand' => $brand,
                'topic' => $topic,
            ];
        }

        private static function loadUser(int $userId)
        {
            if (!class_exists('CUser')) {
                return null;
            }

            $rsUser = \CUser::GetByID($userId);
            if (!is_object($rsUser)) {
                return null;
            }

            $user = $rsUser->Fetch();
            if (!is_array($user)) {
                return null;
            }

            return $user;
        }

        private static function resolveSiteCustomerId(array $user, $fieldName)
        {
            $fieldName = (string)$fieldName;
            if ($fieldName === '' || !isset($user[$fieldName])) {
                return '';
            }

            return trim((string)$user[$fieldName]);
        }

        private static function log($message, array $context = [])
        {
            if (!class_exists('CEventLog')) {
                return;
            }

            \CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'MINDBOX_REGISTER_CUSTOMER_OPS',
                'MODULE_ID' => 'custom',
                'ITEM_ID' => 'MindboxRegisterCustomerOperations',
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
