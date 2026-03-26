<?php

declare(strict_types=1);

namespace Core;

class Response
{
    public static function success(mixed $data, int $code = 200): never
    {
        self::headers($code);
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $code = 400): never
    {
        self::headers($code);
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function headers(int $code): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
}
