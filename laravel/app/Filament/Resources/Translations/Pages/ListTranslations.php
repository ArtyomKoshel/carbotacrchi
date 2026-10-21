<?php

namespace App\Filament\Resources\Translations\Pages;

use App\Filament\Resources\Lots\LotFormat;
use App\Filament\Resources\Translations\TranslationResource;
use App\Models\Translation;
use App\Services\TranslationCatalog;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTranslations extends ListRecords
{
    protected static string $resource = TranslationResource::class;

    protected static ?string $title = 'Переводы';

    /** One tab per category; ~7k rows, so the counts are one GROUP BY. */
    public function getTabs(): array
    {
        $counts = Translation::groupBy('category')->selectRaw('category, COUNT(*) AS n')->pluck('n', 'category');

        return ['all' => Tab::make('Все')->badge(LotFormat::number((int) $counts->sum()))->badgeColor('gray')]
            + collect(TranslationCatalog::LABELS)
                ->filter(fn ($label, $category) => isset($counts[$category]))
                ->map(fn (string $label, string $category) => Tab::make($label)
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('category', $category))
                    ->badge(LotFormat::number((int) $counts[$category]))
                    ->badgeColor('gray'))
                ->all();
    }
}
