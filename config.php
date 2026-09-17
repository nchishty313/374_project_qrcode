<?php

return (object)[
    'db' => (object)[
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'qr_auth_demo',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4'
    ],
    
    'token_ttl' => 60,   
    
    'risk_threshold' => 40,
];
