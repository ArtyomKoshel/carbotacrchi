<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BrowserLinkToken extends Model
{
    public $timestamps    = false;
    public $incrementing  = false;
    protected $primaryKey = 'token';
    protected $keyType    = 'string';

    protected $fillable = [
        'token',
        'chat_id',
        'first_name',
        'username',
        'linked_at',
        'created_at',
        'expires_at',
    ];

    protected $casts = [
        'chat_id'   => 'integer',
        'linked_at' => 'datetime',
        'created_at'=> 'datetime',
        'expires_at'=> 'datetime',
    ];

    /** Generate a new unlinked token valid for 24 hours. */
    public static function generate(): self
    {
        return self::create([
            'token'      => Str::random(48),
            'created_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
    }

    /** Find a valid (non-expired) token. */
    public static function findValid(string $token): ?self
    {
        return self::where('token', $token)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    public function isLinked(): bool
    {
        return $this->chat_id !== null && $this->linked_at !== null;
    }

    /** Link this token to a Telegram user. */
    public function linkTo(int $chatId, string $firstName, string $username): void
    {
        $this->update([
            'chat_id'    => $chatId,
            'first_name' => $firstName,
            'username'   => $username,
            'linked_at'  => now(),
        ]);
    }
}
