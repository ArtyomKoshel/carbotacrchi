<?php

return [
    // off | shadow | primary — which parser feeds lots (App\Ingest\CollectorMode, docs/adr/0007).
    'mode' => env('COLLECTOR_MODE', 'off'),

    // The scheduler consumes the buffer only when the collector feeds lots.
    'enabled' => env('COLLECTOR_MODE', 'off') === 'primary',

    // Sources the collector sweeps (collector:enqueue).
    'sources' => array_values(array_filter(explode(',', (string) env('COLLECTOR_SOURCES', 'encar')))),

    // JSON Schema of the CarLot contract the collector sends (docs/adr/0002).
    // Generated from collector/src/collector/domain/carlot.py; the Docker image
    // copies the repository's contracts/ directory next to the app.
    'carlot_schema' => env('INGEST_CARLOT_SCHEMA', base_path('../contracts/carlot.v1.schema.json')),
];
