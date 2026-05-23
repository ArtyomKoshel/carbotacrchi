<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class AdminUser extends Model
{
    protected $table = 'admin_users';

    protected $fillable = ['username', 'password', 'role'];

    protected $hidden = ['password'];

    public function isSuper(): bool
    {
        return $this->role === 'super';
    }

    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = Hash::make($value);
    }

    public function checkPassword(string $plain): bool
    {
        return Hash::check($plain, $this->password);
    }
}
