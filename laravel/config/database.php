<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', '3306'),
            'database'  => env('DB_DATABASE', 'forge'),
            'username'  => env('DB_USERNAME', 'forge'),
            'password'  => env('DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
            'engine'    => null,
        ],

        // Collector service schema (docs/adr/0001): the core only reads the
        // ingest buffer from it. Same server; its own database and, in prod,
        // its own user.
        'collector' => [
            'driver'    => 'mysql',
            'host'      => env('COLLECTOR_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port'      => env('COLLECTOR_DB_PORT', env('DB_PORT', '3306')),
            'database'  => env('COLLECTOR_DB_DATABASE', 'collector'),
            'username'  => env('COLLECTOR_DB_USERNAME', env('DB_USERNAME', 'forge')),
            'password'  => env('COLLECTOR_DB_PASSWORD', env('DB_PASSWORD', '')),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
            'engine'    => null,
        ],
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),
        'default' => [
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'port'     => env('REDIS_PORT', 6379),
            'database' => 0,
            'password' => env('REDIS_PASSWORD', null),
        ],
        'cache' => [
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'port'     => env('REDIS_PORT', 6379),
            'database' => 1,
            'password' => env('REDIS_PASSWORD', null),
        ],
    ],

    'migrations' => [
        'table'               => 'migrations',
        'update_date_on_publish' => true,
    ],
];
