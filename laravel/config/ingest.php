<?php

return [
    // Feature flag: the scheduler consumes the buffer only when on.
    'enabled' => (bool) env('INGEST_ENABLED', false),

    // JSON Schema of the CarLot contract the collector sends (docs/adr/0002).
    // Generated from collector/src/collector/domain/carlot.py; the Docker image
    // copies the repository's contracts/ directory next to the app.
    'carlot_schema' => env('INGEST_CARLOT_SCHEMA', base_path('../contracts/carlot.v1.schema.json')),
];
