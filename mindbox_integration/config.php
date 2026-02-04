<?php

return [
    'apiUrl' => '...', // example: 'api.mindbox.ru'
    'endpointId' => '...', // example: 'mytestsite.WebsiteLoyalty'
    'secretKeys' => [
        '...' => '', // example: 'mytestsite.WebsiteLoyalty' => '...',
    ],
    'timeout' => 5,
    'operations' => [
        'authorizeCustomer' => [
            'enabled' => false, // set true only after all fields below are configured
            'operation' => '', // example: 'Website.AuthorizeCustomer'
            'mindbox_ids_key' => '', // example: 'IDWebsite'
            'site_customer_id_field' => '', // example: 'ID' or custom user field code
        ],
    ],
    'queue' => [
        'hl_block_name' => 'MindboxQueue',
        'retry_interval_seconds' => 900,
        'agent_interval_seconds' => 300,
        'batch_size' => 50,
        'lock_seconds' => 300,
        'log_channel' => 'mindbox',
    ],
];
