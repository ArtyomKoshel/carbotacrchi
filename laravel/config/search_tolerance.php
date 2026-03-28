<?php

return [
    'enabled' => env('SEARCH_TOLERANCE_ENABLED', true),

    // 'chat_only' — only for AI chat search; 'all' — for Mini App too
    'apply_to' => env('SEARCH_TOLERANCE_APPLY_TO', 'chat_only'),

    'tolerances' => [
        'mileage' => (float) env('TOLERANCE_MILEAGE', 0.30),
        'price'   => (float) env('TOLERANCE_PRICE',   0.20),
        'engine'  => (float) env('TOLERANCE_ENGINE',  0.15),
        'year'    => (int)   env('TOLERANCE_YEAR',    1),
    ],
];
