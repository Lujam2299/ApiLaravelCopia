<?php

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'api'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'), // antes user
    ],

    'guards' => [
        'api' => [
            'driver' => 'sanctum',
            'provider' => 'users', // antes User
            'hash' => false,
        ],
    ],

    'providers' => [
        'users' => [ // antes api_user
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', App\Models\User::class),
        ],
    ],

    'passwords' => [
        'users' => [ // antes api_user
            'provider' => 'users', // antes User
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
