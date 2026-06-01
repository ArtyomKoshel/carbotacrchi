<?php

return [
    'bot_token'          => env('TELEGRAM_BOT_TOKEN', ''),
    'webhook_secret'     => env('TELEGRAM_WEBHOOK_SECRET', ''),
    'miniapp_url'        => env('MINIAPP_URL', 'https://localhost:8080/miniapp/'),
    'data_dir'           => storage_path('app/data'),
    'sources'            => array_filter(explode(',', env('PARSER_SOURCES', 'encar'))),
    'floppydata_api_key'  => env('FLOPPYDATA_API_KEY', ''),
    'floppy_base_url'    => env('FLOPPY_BASE_URL', 'https://api.floppydata.net'),
];
