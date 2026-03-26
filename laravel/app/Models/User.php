<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $fillable = ['id', 'username', 'first_name', 'last_seen'];

    public $incrementing = false;
    protected $keyType   = 'integer';

    public function searches(): HasMany
    {
        return $this->hasMany(Search::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }
}
