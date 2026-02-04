<?php

$path = __DIR__ . '/bootstrap.php';
if (is_file($path)) {
    require_once $path;
}

if (class_exists('MindboxCustomerOperations', false) && method_exists('MindboxCustomerOperations', 'registerHandlers')) {
    try {
        MindboxCustomerOperations::registerHandlers();
    } catch (\Throwable $e) {
        // ignore handler registration failures to keep integration boot-safe
    }
}

if (!function_exists('mindbox_integration_agent')) {
    function mindbox_integration_agent()
    {
        $path = __DIR__ . '/bootstrap.php';
        if (is_file($path)) {
            require_once $path;
        }

        if (class_exists('MindboxIntegrationQueueService', false)) {
            return MindboxIntegrationQueueService::agent();
        }

        return 'mindbox_integration_agent();';
    }
}
