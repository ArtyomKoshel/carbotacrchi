<?php

return [
    'token'    => env('ADMIN_TOKEN', ''),
    'log_file' => env('PARSER_LOG_FILE', '/app/logs/parser.log'),
    'log_lines' => (int) env('ADMIN_LOG_LINES', 1000),
];
