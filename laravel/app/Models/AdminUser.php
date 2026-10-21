<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class AdminUser extends Model implements AuthenticatableContract, FilamentUser, HasName
{
    use Authenticatable;

    protected $table = 'admin_users';

    protected $fillable = ['username', 'password', 'role'];

    protected $hidden = ['password'];

    public function isSuper(): bool
    {
        return $this->role === 'super';
    }

    /**
     * A page of the admin is open to super admins always and to limited
     * admins when its key is enabled in admin_page_permissions.
     */
    public function canSeePage(?string $pageKey): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        return $pageKey !== null && in_array($pageKey, AdminPagePermission::allowedKeysCached(), true);
    }

    public function setPasswordAttribute(string $value): void
    {
        // The auth provider may write back an already hashed password (rehash on login).
        $this->attributes['password'] = Hash::isHashed($value) ? $value : Hash::make($value);
    }

    public function checkPassword(string $plain): bool
    {
        return Hash::check($plain, $this->password);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, ['super', 'limited'], true);
    }

    public function getFilamentName(): string
    {
        return $this->username;
    }

    /** admin_users has no remember_token column: "remember me" is not supported. */
    public function getRememberTokenName()
    {
        return '';
    }
}
