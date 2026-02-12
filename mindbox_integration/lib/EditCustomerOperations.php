<?php

if (!class_exists('MindboxEditCustomerOperations', false)) {
    class MindboxEditCustomerOperations
    {
        private static $handlersRegistered = false;

        /**
         * Bitrix field -> Mindbox customer field mapping.
         */
        private static $fieldMap = [
            'NAME' => 'firstName',
            'LAST_NAME' => 'lastName',
            'SECOND_NAME' => 'middleName',
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
            'middleName' => 'SECOND_NAME',
            'email' => 'EMAIL',
            'mobilePhone' => 'PERSONAL_PHONE',
            'customFields' => null, // resolved dynamically to city_field
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

            AddEventHandler('main', 'OnBeforeUserUpdate', __CLASS__ . '::onBeforeUserUpdate');
            self::$handlersRegistered = true;
        }

        /**
         * OnBeforeUserUpdate handler.
         *
         * Returns false + $APPLICATION->ThrowException to cancel the update on validation errors.
         * Returns nothing (void) to allow the update on success or Mindbox unavailability.
         *
         * @param array &$arFields Fields being updated (only changed fields + ID).
         * @return bool|void
         */
        public static function onBeforeUserUpdate(&$arFields)
        {
            if (!is_array($arFields)) {
                return;
            }

            $userId = (int)($arFields['ID'] ?? 0);
            if ($userId <= 0) {
                return;
            }

            try {
                $settings = self::settings();
                if ($settings === null) {
                    return;
                }

                if (!self::hasProfileFields($arFields, $settings)) {
                    return;
                }

                $currentUser = self::loadUser($userId);
                if ($currentUser === null) {
                    return;
                }

                $merged = array_merge($currentUser, $arFields);

                $payload = self::buildPayload($merged, $settings);
                if ($payload === null) {
                    return;
                }

                MindboxIntegration::sendSync(
                    $settings['operation'],
                    $payload,
                    null,
                    true,
                    null,
                    null,
                    true
                );

                // Success or queued (null return) — allow save
            } catch (MindboxValidationException $e) {
                $errors = self::parseValidationErrors($e, $settings);
                self::storeValidationErrors($errors);

                $message = self::formatErrorMessage($errors);
                self::cancelUpdate($message);

                return false;
            } catch (\Throwable $e) {
                self::log('onBeforeUserUpdate failed: ' . $e->getMessage(), [
                    'user_id' => $userId,
                    'exception' => get_class($e),
                ]);
                // Allow save on unexpected errors — don't block user
            }
        }

        /**
         * Public API: send EditCustomer for a given user.
         *
         * Can be called from custom controllers, AJAX handlers, etc.
         *
         * @param int $userId Bitrix user ID.
         * @param array|null $overrideFields Override specific fields (Bitrix field names).
         * @return array ['success' => bool, 'errors' => [bitrix_field => message, ...]]
         */
        public static function sendEditCustomer(int $userId, $overrideFields = null)
        {
            if ($userId <= 0) {
                return ['success' => false, 'errors' => ['_general' => 'Invalid user ID']];
            }

            $settings = self::settings();
            if ($settings === null) {
                return ['success' => false, 'errors' => ['_general' => 'EditCustomer operation is disabled']];
            }

            $currentUser = self::loadUser($userId);
            if ($currentUser === null) {
                return ['success' => false, 'errors' => ['_general' => 'User not found']];
            }

            $merged = is_array($overrideFields) ? array_merge($currentUser, $overrideFields) : $currentUser;

            $payload = self::buildPayload($merged, $settings);
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
                    true
                );

                return ['success' => true, 'errors' => []];
            } catch (MindboxValidationException $e) {
                $errors = self::parseValidationErrors($e, $settings);
                return ['success' => false, 'errors' => $errors];
            } catch (\Throwable $e) {
                self::log('sendEditCustomer failed: ' . $e->getMessage(), [
                    'user_id' => $userId,
                    'exception' => get_class($e),
                ]);
                // Queued or logged by QueueService — treat as "accepted"
                return ['success' => true, 'errors' => []];
            }
        }

        /**
         * Retrieve and clear validation errors stored in session.
         *
         * Use in templates to display field-level errors:
         *   $errors = MindboxEditCustomerOperations::getLastValidationErrors();
         *   if ($errors && isset($errors['EMAIL'])) { echo $errors['EMAIL']; }
         *
         * @return array|null ['BITRIX_FIELD' => 'error message', ...] or null
         */
        public static function getLastValidationErrors()
        {
            if (!isset($_SESSION['MINDBOX_EDIT_CUSTOMER_ERRORS'])) {
                return null;
            }

            $errors = $_SESSION['MINDBOX_EDIT_CUSTOMER_ERRORS'];
            unset($_SESSION['MINDBOX_EDIT_CUSTOMER_ERRORS']);

            return is_array($errors) ? $errors : null;
        }

        // ---- Private helpers ----

        /**
         * Build the Mindbox API payload from merged user data.
         *
         * @param array $merged   Current user data merged with new values.
         * @param array $settings Operation settings.
         * @return array|null Payload array or null if required fields are missing.
         */
        private static function buildPayload(array $merged, array $settings)
        {
            $siteCustomerId = self::resolveSiteCustomerId($merged, $settings['site_customer_id_field']);
            if ($siteCustomerId === '') {
                return null;
            }

            $customer = [];

            // Standard fields
            foreach (self::$fieldMap as $bitrixField => $mindboxField) {
                $value = trim((string)($merged[$bitrixField] ?? ''));
                if ($value === '') {
                    continue;
                }

                $customer[$mindboxField] = $value;
            }

            // IDs
            $customer['ids'] = [
                $settings['mindbox_ids_key'] => $siteCustomerId,
            ];

            // City as customField
            $cityField = $settings['city_field'];
            $cityValue = trim((string)($merged[$cityField] ?? ''));
            if ($cityValue !== '') {
                $customer['customFields'] = [
                    'city' => $cityValue,
                ];
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
         * @param string $location e.g. "customer.email", "customer.customFields.city"
         * @param array $settings
         * @return string Bitrix field name or '_general'
         */
        private static function mapLocationToBitrixField($location, array $settings)
        {
            if ($location === '') {
                return '_general';
            }

            // Check for customFields (city)
            if (strpos($location, 'customFields') !== false) {
                return $settings['city_field'];
            }

            // Check standard field mappings
            foreach (self::$locationMap as $mindboxKeyword => $bitrixField) {
                if ($bitrixField !== null && strpos($location, $mindboxKeyword) !== false) {
                    return $bitrixField;
                }
            }

            return '_general';
        }

        /**
         * Format structured errors into a human-readable message for Bitrix.
         *
         * @param array $errors ['FIELD' => 'message', ...]
         * @return string
         */
        private static function formatErrorMessage(array $errors)
        {
            $fieldLabels = [
                'NAME' => 'Name',
                'LAST_NAME' => 'Last name',
                'SECOND_NAME' => 'Middle name',
                'EMAIL' => 'Email',
                'PERSONAL_PHONE' => 'Phone',
                'PERSONAL_CITY' => 'City',
            ];

            $parts = [];
            foreach ($errors as $field => $message) {
                if ($field === '_general') {
                    $parts[] = $message;
                } else {
                    $label = isset($fieldLabels[$field]) ? $fieldLabels[$field] : $field;
                    $parts[] = $label . ': ' . $message;
                }
            }

            return implode('<br>', $parts);
        }

        /**
         * Store validation errors in session for field-level display in templates.
         *
         * @param array $errors
         */
        private static function storeValidationErrors(array $errors)
        {
            if (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE) {
                $_SESSION['MINDBOX_EDIT_CUSTOMER_ERRORS'] = $errors;
            }
        }

        /**
         * Cancel the CUser::Update by throwing a Bitrix application exception.
         *
         * @param string $message
         */
        private static function cancelUpdate($message)
        {
            global $APPLICATION;

            if (is_object($APPLICATION) && method_exists($APPLICATION, 'ThrowException')) {
                $APPLICATION->ThrowException(new \CApplicationException($message));
            }
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
            if (isset($config['operations']['editCustomer']) && is_array($config['operations']['editCustomer'])) {
                $raw = $config['operations']['editCustomer'];
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

            $cityField = trim((string)($raw['city_field'] ?? 'PERSONAL_CITY'));

            return [
                'operation' => $operation,
                'mindbox_ids_key' => $mindboxIdsKey,
                'site_customer_id_field' => $siteCustomerIdField,
                'city_field' => $cityField !== '' ? $cityField : 'PERSONAL_CITY',
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

        /**
         * Check if $arFields contains at least one profile field we track.
         * Prevents firing on system updates (LAST_LOGIN, STORED_HASH, etc.)
         */
        private static function hasProfileFields(array $arFields, array $settings)
        {
            $trackedFields = array_keys(self::$fieldMap);
            $trackedFields[] = $settings['city_field'];

            foreach ($trackedFields as $field) {
                if (array_key_exists($field, $arFields)) {
                    return true;
                }
            }

            return false;
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
                'AUDIT_TYPE_ID' => 'MINDBOX_EDIT_CUSTOMER_OPS',
                'MODULE_ID' => 'custom',
                'ITEM_ID' => 'MindboxEditCustomerOperations',
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
