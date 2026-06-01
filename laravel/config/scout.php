<?php

return [
    'driver' => env('SCOUT_DRIVER', 'meilisearch'),

    'prefix' => env('SCOUT_PREFIX', ''),

    'queue' => [
        'connection' => false,
        'queue'      => 'scout',
    ],

    'after_commit' => false,

    'chunk' => [
        'searchable'   => 500,
        'unsearchable' => 500,
    ],

    'soft_delete' => false,

    'identify' => false,

    'meilisearch' => [
        'host'    => env('MEILISEARCH_HOST', 'http://meilisearch:7700'),
        'key'     => env('MEILISEARCH_KEY', ''),
        'index-settings' => [
            'lots' => [
                'filterableAttributes' => [
                    'source', 'make', 'model', 'year', 'price', 'mileage',
                    'transmission', 'fuel', 'body_type', 'drive_type',
                    'is_active', 'has_accident', 'flood_history',
                ],
                'sortableAttributes' => ['price', 'mileage', 'year', 'listed_at'],
                'searchableAttributes' => [
                    'make', 'model', 'model_en', 'generation', 'trim', 'badge',
                    'color', 'location', 'vin',
                ],
            ],
        ],
    ],
];
