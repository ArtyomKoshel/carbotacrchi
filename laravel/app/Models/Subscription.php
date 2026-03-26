<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'query',
        'known_lot_ids',
        'last_checked_at',
        'new_lots_count',
        'new_lot_previews',
        'active',
    ];

    protected $casts = [
        'query'          => 'array',
        'known_lot_ids'  => 'array',
        'new_lots_count'   => 'integer',
        'new_lot_previews' => 'array',
        'last_checked_at'=> 'datetime',
        'active'         => 'boolean',
    ];

    public function scopeActive($q)
    {
        return $q->where('active', true);
    }

    public function label(): string
    {
        $q = $this->query ?? [];
        $parts = array_filter([
            isset($q['make'])     ? $q['make']  : null,
            isset($q['model'])    ? $q['model'] : null,
            isset($q['yearFrom']) ? ($q['yearFrom'].'–'.($q['yearTo'] ?? '…')) : null,
            isset($q['priceMax']) ? ('≤ $'.number_format($q['priceMax'])) : null,
        ]);
        return implode(' · ', $parts) ?: 'Все лоты';
    }
}
