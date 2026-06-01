<?php

namespace App\Bot;

class BotContext
{
    public function __construct(
        public readonly int|string $chatId,
        public readonly int|string $userId,
        public readonly string     $firstName = '',
        public readonly string     $username  = '',
    ) {}
}
