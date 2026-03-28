<?php

return [
    'anthropic_key' => env('ANTHROPIC_API_KEY', ''),
    'model'         => env('AI_MODEL', 'claude-haiku-4-5-20251001'),
    'max_tokens'    => 300,
    'temperature'   => 0,
];
