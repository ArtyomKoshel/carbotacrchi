<?php

return [
    'api_key'     => env('AI_API_KEY', ''),
    'api_url'     => env('AI_API_URL', 'https://api.groq.com/openai/v1/chat/completions'),
    'model'       => env('AI_MODEL', 'meta-llama/llama-4-scout-17b-16e-instruct'),
    'max_tokens'  => 300,
    'temperature' => 0,
];
