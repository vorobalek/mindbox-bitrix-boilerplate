<?php

if (!class_exists('MindboxCustomerOperations', false)) {
    class MindboxCustomerOperations
    {
        private const DEVICE_UUID_COOKIE = 'mindboxDeviceUUID';

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

            AddEventHandler('main', 'OnAfterUserAuthorize', __CLASS__ . '::onAfterUserAuthorize');
            self::$handlersRegistered = true;
        }

        public static function onAfterUserAuthorize($params = [])
        {
            if (!is_array($params)) {
                return;
            }

            try {
                $userId = (int)($params['user_fields']['ID'] ?? 0);
                if ($userId <= 0) {
                    return;
                }

                $deviceUUID = self::extractDeviceUUID();
                self::sendAuthorizeCustomerByUserId($userId, $deviceUUID);
            } catch (\Throwable $e) {
                self::log('onAfterUserAuthorize failed: ' . $e->getMessage(), $params);
            }
        }

        public static function sendAuthorizeCustomerByUserId(int $userId, ?string $deviceUUID = null): bool
        {
            if ($userId <= 0) {
                return false;
            }

            $settings = self::settings();
            if ($settings === null) {
                return false;
            }

            $user = self::loadUser($userId);
            if ($user === null) {
                return false;
            }

            return self::sendAuthorizeCustomerWithSettings($user, $deviceUUID, $settings);
        }

        public static function sendAuthorizeCustomer(array $user, ?string $deviceUUID = null): bool
        {
            if (!class_exists('MindboxIntegration', false)) {
                return false;
            }

            $settings = self::settings();
            if ($settings === null) {
                return false;
            }

            return self::sendAuthorizeCustomerWithSettings($user, $deviceUUID, $settings);
        }

        private static function sendAuthorizeCustomerWithSettings(array $user, ?string $deviceUUID, array $settings): bool
        {
            if (!class_exists('MindboxIntegration', false)) {
                return false;
            }

            $email = trim((string)($user['EMAIL'] ?? ''));
            if ($email === '') {
                return false;
            }

            $siteCustomerId = self::resolveSiteCustomerId($user, $settings['site_customer_id_field']);
            if ($siteCustomerId === '') {
                return false;
            }

            $uuid = $deviceUUID !== null ? trim($deviceUUID) : '';

            MindboxIntegration::sendAsync(
                $settings['operation'],
                [
                    'customer' => [
                        'email' => $email,
                        'ids' => [
                            $settings['mindbox_ids_key'] => $siteCustomerId,
                        ],
                    ],
                ],
                $uuid !== '' ? $uuid : null,
                true
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
            if (isset($config['operations']['authorizeCustomer']) && is_array($config['operations']['authorizeCustomer'])) {
                $raw = $config['operations']['authorizeCustomer'];
            }
            if ($raw === []) {
                return null;
            }

            $enabled = isset($raw['enabled']) ? (bool)$raw['enabled'] : false;
            $operation = trim((string)($raw['operation'] ?? ''));
            $mindboxIdsKey = trim((string)($raw['mindbox_ids_key'] ?? ''));
            $siteCustomerIdField = trim((string)($raw['site_customer_id_field'] ?? ''));

            if (!$enabled || $operation === '' || $mindboxIdsKey === '' || $siteCustomerIdField === '') {
                return null;
            }

            return [
                'operation' => $operation,
                'mindbox_ids_key' => $mindboxIdsKey,
                'site_customer_id_field' => $siteCustomerIdField,
            ];
        }

        private static function extractDeviceUUID(): ?string
        {
            if (isset($_COOKIE[self::DEVICE_UUID_COOKIE])) {
                $value = trim((string)$_COOKIE[self::DEVICE_UUID_COOKIE]);
                if ($value !== '') {
                    return $value;
                }
            }

            return null;
        }

        private static function loadUser(int $userId): ?array
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

        private static function resolveSiteCustomerId(array $user, string $fieldName): string
        {
            if ($fieldName === '' || !isset($user[$fieldName])) {
                return '';
            }

            return trim((string)$user[$fieldName]);
        }

        private static function log(string $message, array $context = []): void
        {
            if (!class_exists('CEventLog')) {
                return;
            }

            \CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'MINDBOX_CUSTOMER_OPS',
                'MODULE_ID' => 'custom',
                'ITEM_ID' => 'MindboxCustomerOperations',
                'DESCRIPTION' => $message . ' | context: ' . self::toJson($context),
            ]);
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
