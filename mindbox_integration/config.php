<?php

return [
    'apiUrl' => '...', // example: 'api.mindbox.ru'
    'endpointId' => '...', // example: 'mytestsite.WebsiteLoyalty'
    'secretKeys' => [
        '...' => '', // example: 'mytestsite.WebsiteLoyalty' => '...',
    ],
    'timeout' => 5,
    'queue' => [
        'hl_block_name' => 'MindboxQueue',
        'retry_interval_seconds' => 900,
        'agent_interval_seconds' => 300,
        'batch_size' => 50,
        'lock_seconds' => 300,
        'log_channel' => 'mindbox',
    ],
];
