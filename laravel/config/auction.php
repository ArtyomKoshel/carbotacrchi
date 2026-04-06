<?php

return [
    'bot_token'      => env('TELEGRAM_BOT_TOKEN', ''),
    'miniapp_url'    => env('MINIAPP_URL', 'https://localhost:8080/miniapp/'),
    'data_dir'       => storage_path('app/data'),
    'lot_repository' => env('LOT_REPOSITORY', 'mock'),
    'sources'        => array_filter(explode(',', env('PARSER_SOURCES', 'kbcha'))),
];
