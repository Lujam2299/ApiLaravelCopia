<?php

// config/cors.php

return [
    'paths' => [
        'api/*',
        'broadcasting/*',
        'broadcasting/auth',
        'broadcasting/join-channel',
        'broadcasting/leave-channel',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:*',
        'http://127.0.0.1:*',
        'http://192.168.1.3:*',
        'exp://*',
        'https://exp.host',
        'https://*.exp.host',
        'http://localhost:19000',
        'http://localhost:19001',
        'exp://192.168.1.3:*',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
