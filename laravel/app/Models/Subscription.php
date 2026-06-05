<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'query',
        'last_checked_at',
        'new_lots_count',
        'new_lot_previews',
        'active',
    ];

    protected $casts = [
        'query'            => 'array',
        'new_lots_count'   => 'integer',
        'new_lot_previews' => 'array',
        'last_checked_at'  => 'datetime',
        'active'           => 'boolean',
    ];

    public function scopeActive($q)
    {
        return $q->where('active', true);
    }

    public function seenLots(): HasMany
    {
        return $this->hasMany(SubscriptionSeenLot::class);
    }

    public function getSeenLotIds(): array
    {
        return $this->seenLots()->pluck('lot_id')->all();
    }

    public function markLotsAsSeen(array $lotIds): void
    {
        if (empty($lotIds)) {
            return;
        }

        $rows = array_map(fn ($id) => [
            'subscription_id' => $this->id,
            'lot_id'          => (string) $id,
            'created_at'      => now(),
        ], $lotIds);

        DB::table('subscription_seen_lots')->insertOrIgnore($rows);
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
