<?php

namespace App\Filament\Resources\RefVariants\Pages;

use App\Filament\Resources\Lots\LotFormat;
use App\Filament\Resources\RefVariants\RefVariantResource;
use App\Models\RefVariant;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRefVariants extends ListRecords
{
    protected static string $resource = RefVariantResource::class;

    protected static ?string $title = 'Справочник: разбор';

    /** One tab per status, the badge counts active lots; «Вручную» are the hand-placed ones. */
    public function getTabs(): array
    {
        $lots = RefVariant::whereNotNull('make_id')->groupBy('status')->selectRaw('status, SUM(lots) AS n')->pluck('n', 'status');
        $manual = (int) RefVariant::whereNotNull('make_id')->where('manual', true)->count();

        $tabs = [];
        foreach (RefVariantResource::STATUSES as $status => $label) {
            $tabs[$status] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status))
                ->badge(LotFormat::number((int) ($lots[$status] ?? 0)))
                ->badgeColor($status === 'matched' ? 'gray' : 'warning');
        }
        $tabs['manual'] = Tab::make('Вручную')->modifyQueryUsing(fn (Builder $query) => $query->where('manual', true))
            ->badge(LotFormat::number($manual))->badgeColor('gray');

        return $tabs;
    }
}
