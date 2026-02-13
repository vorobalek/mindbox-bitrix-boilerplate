Mindbox Integration (Bitrix)

Runbook: installation / removal (local only, without /bitrix changes)

Installation
1) Copy the folder to Bitrix local:
   - Source: <repo>/mindbox_integration
   - Target: /local/php_interface/mindbox_integration

2) Connect the integration in /local/php_interface/init.php:
   - Add:
     if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/mindbox_integration/include.php')) {
         require_once $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/mindbox_integration/include.php';
     }

3) Configure settings in /local/php_interface/mindbox_integration/config.php:
   - apiUrl
   - endpointId
   - secretKeys (map by endpointId)
   - timeout (optional)

4) Run installation (admin session):
   - Open in browser:
     /local/php_interface/mindbox_integration/admin_install.php
   - This will create HL storage (queue) and register the agent.

5) Use in code (example):
   - MindboxIntegration::sendAsync($operation, $payload, $deviceUUID, $authorization);
   - MindboxIntegration::sendSync($operation, $payload, $deviceUUID, $authorization);

6) Website.AuthorizeCustomer (separate file, safe autoload):
   - File location:
     /local/php_interface/mindbox_integration/lib/CustomerOperations.php
   - Safe connection is automatic from bootstrap/include (with file/class checks).
   - If configuration is incomplete, handlers are not registered and nothing is sent.
   - On successful site authorization (`main:OnAfterUserAuthorize`) the integration sends
     operation from config via `MindboxIntegration::sendAsync(..., true)`.
   - User ID is taken strictly from `user_fields.ID` of the event params.
   - Configure operation behavior in config.php:
     - operations.authorizeCustomer.enabled
     - operations.authorizeCustomer.operation
     - operations.authorizeCustomer.mindbox_ids_key
     - operations.authorizeCustomer.site_customer_id_field
   - deviceUUID cookie name is fixed: `mindboxDeviceUUID`

7) Website.OutOfStock for "subscribe to product":
   - File location:
     /local/php_interface/mindbox_integration/lib/OutOfStockOperations.php
   - Safe connection is automatic from bootstrap/include (with file/class checks).
   - If configuration is incomplete, handlers are not registered and nothing is sent.
   - Handler listens `iblock:OnAfterIBlockElementAdd` and sends operation from config.
   - Default mapping is compatible with payload from:
     - subscription element `NAME` -> customer email
     - subscription element `CODE` -> product id in website ids
   - Configure operation behavior in config.php:
     - operations.outOfStock.enabled
     - operations.outOfStock.operation
     - operations.outOfStock.subscription_iblock_id
     - operations.outOfStock.product_ids_key
     - operations.outOfStock.brand
     - operations.outOfStock.point_of_contact
     - operations.outOfStock.topic
     - operations.outOfStock.email_field
     - operations.outOfStock.product_id_field
     - operations.outOfStock.authorization

8) Website.EditCustomer for profile editing:
   - File location:
     /local/php_interface/mindbox_integration/lib/EditCustomerOperations.php
   - Safe connection is automatic from bootstrap/include (with file/class checks).
   - If configuration is incomplete, handlers are not registered and nothing is sent.
   - Handler listens `main:OnBeforeUserUpdate` and sends customer data to Mindbox
     BEFORE saving to Bitrix DB.
   - On successful Mindbox response: Bitrix save proceeds normally.
   - On validation error from Mindbox: save is cancelled, errors are shown to user
     via $APPLICATION->ThrowException(). Structured per-field errors are stored in
     $_SESSION['MINDBOX_EDIT_CUSTOMER_ERRORS'] for template-level display.
   - On Mindbox unavailability (5XX/transport): request is queued for retry every
     15 minutes, Bitrix save proceeds (graceful degradation).
   - On 4XX errors: request is logged for manual review, Bitrix save proceeds.
   - Configure operation behavior in config.php:
     - operations.editCustomer.enabled
     - operations.editCustomer.operation
     - operations.editCustomer.mindbox_ids_key
     - operations.editCustomer.site_customer_id_field
     - operations.editCustomer.city_field (default: PERSONAL_CITY)
   - Payload sent to Mindbox:
     {
       "customer": {
         "lastName": "<LAST_NAME>",
         "firstName": "<NAME>",
         "middleName": "<SECOND_NAME>",
         "email": "<EMAIL>",
         "mobilePhone": "<PERSONAL_PHONE>",
         "ids": { "<mindbox_ids_key>": "<site_customer_id>" },
         "customFields": { "city": "<city_field value>" }
       }
     }
   - Public API for programmatic use:
     $result = MindboxEditCustomerOperations::sendEditCustomer($userId);
     // returns ['success' => bool, 'errors' => ['FIELD' => 'message', ...]]
   - Template helper for field-level error display:
     $errors = MindboxEditCustomerOperations::getLastValidationErrors();
     if ($errors && isset($errors['EMAIL'])) { echo $errors['EMAIL']; }

9) Website.RegisterCustomer for user registration:
   - File location:
     /local/php_interface/mindbox_integration/lib/RegisterCustomerOperations.php
   - Safe connection is automatic from bootstrap/include (with file/class checks).
   - If configuration is incomplete, handlers are not registered and nothing is sent.
   - Handler listens `main:OnAfterUserRegister` and sends customer data to Mindbox
     AFTER Bitrix registration completes. Registration is never blocked by Mindbox errors.
   - Standard registration (via Bitrix event) always sends with Email+SMS subscriptions
     and without discount card.
   - Configure operation behavior in config.php:
     - operations.registerCustomer.enabled
     - operations.registerCustomer.operation
     - operations.registerCustomer.mindbox_ids_key
     - operations.registerCustomer.site_customer_id_field (default: ID)
     - operations.registerCustomer.discount_card_ids_key (for discount card integration)
     - operations.registerCustomer.brand (subscription brand, e.g. 'podpisnie')
     - operations.registerCustomer.topic (subscription topic, e.g. 'izdaniya')
   - Payload sent to Mindbox:
     {
       "customer": {
         "firstName": "<NAME>",
         "lastName": "<LAST_NAME>",
         "email": "<EMAIL>",
         "mobilePhone": "<PERSONAL_PHONE>",
         "ids": { "<mindbox_ids_key>": "<site_customer_id>" },
         "discountCard": { "ids": { "<discount_card_ids_key>": "<card_id>" } },
         "subscriptions": [
           { "brand": "<brand>", "pointOfContact": "Email", "topic": "<topic>" },
           { "brand": "<brand>", "pointOfContact": "SMS", "topic": "<topic>" }
         ]
       }
     }
     discountCard is included only when discountCardId is provided AND
     discount_card_ids_key is configured. Each subscription entry is included
     only when the corresponding flag is true AND brand/topic are configured.
   - Public API for programmatic use (e.g. from ApiDiscountCard):
     $result = MindboxRegisterCustomerOperations::sendRegisterCustomer($userId, [
         'subscribeEmail' => true,
         'subscribeSms'   => false,
         'discountCardId' => '3685306193228',
     ]);
     // returns ['success' => bool, 'errors' => ['FIELD' => 'message', ...]]

10) Website.GetByCard — get customer info by discount card:
   - File location:
     /local/php_interface/mindbox_integration/lib/GetByCardOperations.php
   - Safe connection is automatic from bootstrap/include (with file/class checks).
   - No event handlers — called explicitly from code.
   - Configure operation behavior in config.php:
     - operations.getByCard.enabled
     - operations.getByCard.operation
     - operations.getByCard.discount_card_ids_key (e.g. 'number')
   - Payload sent to Mindbox:
     {
       "customer": {
         "discountCard": {
           "ids": { "<discount_card_ids_key>": "<card_number>" }
         }
       }
     }
   - Public API:
     $result = MindboxGetByCardOperations::getByCard('3685306193228');
     // returns ['success' => bool, 'data' => <Mindbox response>, 'errors' => [...]]

11) Website.GetCustomerBonusPointsHistory — bonus points history:
   - File location:
     /local/php_interface/mindbox_integration/lib/BonusPointsHistoryOperations.php
   - Safe connection is automatic from bootstrap/include (with file/class checks).
   - No event handlers — called explicitly from code.
   - Configure operation behavior in config.php:
     - operations.getCustomerBonusPointsHistory.enabled
     - operations.getCustomerBonusPointsHistory.operation
     - operations.getCustomerBonusPointsHistory.mindbox_ids_key (e.g. 'websiteID')
   - Payload sent to Mindbox:
     {
       "page": {
         "pageNumber": 1,
         "itemsPerPage": 20,
         "sinceDateTimeUtc": "<optional>",
         "tillDateTimeUtc": "<optional>"
       },
       "customer": {
         "ids": { "<mindbox_ids_key>": "<customer_id>" }
       }
     }
   - Public API:
     $result = MindboxBonusPointsHistoryOperations::getBonusPointsHistory('123', [
         'pageNumber'   => 1,
         'itemsPerPage' => 20,
     ]);
     // returns ['success' => bool, 'data' => <Mindbox response>, 'errors' => [...]]

Re-install after a failed install
- It is safe to run /local/php_interface/mindbox_integration/admin_install.php again.
- If some artifacts already exist, the installer will reuse them.

Removal
1) Run uninstall (admin session):
   - Open in browser:
     /local/php_interface/mindbox_integration/admin_uninstall.php
   - Optional: add ?force=Y to remove even if some parts are missing.

2) Remove include from /local/php_interface/init.php (or comment it).

3) Delete files:
   - /local/php_interface/mindbox_integration

Notes
- The package is self-contained and uses only /local/ paths.
- It is safe to uninstall even after a broken/partial installation.
