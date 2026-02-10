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
        'outOfStock' => [
            'enabled' => false, // set true only after all fields below are configured
            'operation' => '', // example: 'Website.OutOfStock'
            'subscription_iblock_id' => 0, // example: 9
            'product_ids_key' => 'website',
            'brand' => '',
            'point_of_contact' => 'Email',
            'topic' => '',
            'email_field' => 'NAME', // where email is stored in subscription element
            'product_id_field' => 'CODE', // where product id is stored in subscription element
            'authorization' => true,
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
