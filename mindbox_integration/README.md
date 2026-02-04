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
