<?php

namespace App\Filament\Resources\Lots\Pages;

use App\Filament\Resources\Lots\LotFormat;
use App\Filament\Resources\Lots\LotResource;
use App\Models\Lot;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListLots extends ListRecords
{
    protected static string $resource = LotResource::class;

    protected static ?string $title = 'Лоты';

    /**
     * Saved views. Counts load once per page open, after the table (deferBadge),
     * and each uses an index or a narrow scan: is_active, lot_changes(recorded_at,
     * event), PK. "Новые сегодня" and "Без комплектации" scan lots (~0.2–0.4 s on
     * 290k rows); no index was added for them.
     */
    public function getTabs(): array
    {
        return [
            'active' => $this->tab('Все активные', fn (Builder $query) => $query->where('is_active', true)),
            'new' => $this->tab('Новые сегодня', fn (Builder $query) => $query->where('created_at', '>=', now()->startOfDay()))
                ->badgeTooltip('Впервые найдены парсером сегодня (UTC)'),
            'delisted' => $this->tab('Снятые за 24 ч', fn (Builder $query) => $query->whereIn('id', DB::table('lot_changes')
                ->select('lot_id')
                ->where('recorded_at', '>=', now()->subDay())
                ->where('event', 'delisted'))),
            'no_trim' => $this->tab('Без комплектации', fn (Builder $query) => $query->where('is_active', true)->whereNull('trim'))
                ->badgeTooltip('Активные лоты с пустой комплектацией (trim)'),
            'all' => $this->tab('Все', fn (Builder $query) => $query),
        ];
    }

    private function tab(string $label, \Closure $scope): Tab
    {
        return Tab::make($label)
            ->modifyQueryUsing($scope)
            ->badge(fn () => LotFormat::number($scope(Lot::query())->count()))
            ->badgeColor('gray')
            ->deferBadge();
    }
}
