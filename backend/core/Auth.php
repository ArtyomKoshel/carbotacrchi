<?php

declare(strict_types=1);

namespace Core;

class Auth
{
    public static function validate(string $initData): bool
    {
        if (APP_ENV === 'development') {
            return true;
        }

        if ($initData === '') {
            return false;
        }

        parse_str($initData, $params);
        $hash = $params['hash'] ?? '';
        unset($params['hash']);

        ksort($params);
        $dataCheckString = implode("\n", array_map(
            fn($k, $v) => "$k=$v",
            array_keys($params),
            array_values($params)
        ));

        $secretKey    = hash_hmac('sha256', BOT_TOKEN, 'WebAppData', true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($expectedHash, $hash);
    }
}
