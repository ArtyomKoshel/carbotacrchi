<?php

return [
    'enabled' => env('SEARCH_TOLERANCE_ENABLED', true),

    'tolerances' => [
        'mileage' => (float) env('TOLERANCE_MILEAGE', 0.30),
        'price'   => (float) env('TOLERANCE_PRICE',   0.20),
        'engine'  => (float) env('TOLERANCE_ENGINE',  0.15),
        'year'    => (int)   env('TOLERANCE_YEAR',    1),
    ],
];
