<?php

namespace App\Filament\Resources\LotChanges;

use App\Filament\Resources\LotChanges\Pages\ListLotChanges;
use App\Filament\Resources\Lots\LotFormat;
use App\Filament\Resources\Lots\LotResource;
use App\Models\LotChange;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\AdminUiLabels;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * «Изменения лотов»: everything the parser recorded in lot_changes (~850k rows
 * on prod), newest first. The period filter keeps queries on the
 * (recorded_at, event) index.
 */
class LotChangeResource extends Resource
{
    public const EVENTS = ['update', 'delisted', 'relisted', 'deactivated_filter'];

    public const PERIODS = ['1' => 'за сутки', '7' => 'за 7 дней', '30' => 'за 30 дней'];

    protected static ?string $model = LotChange::class;

    protected static ?string $modelLabel = 'изменение';

    protected static ?string $pluralModelLabel = 'изменения лотов';

    protected static ?string $navigationLabel = 'Изменения лотов';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_DATA;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'lot-changes';

    public static function canViewAny(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('changes') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('lot:id,make,model,year,ref_make_id,ref_model_id,ref_generation_id'))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('recorded_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('recorded_at')->label('Когда')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('lot_id')->label('Лот')
                    ->state(fn (LotChange $r) => $r->lot ? LotFormat::title($r->lot) : $r->lot_id)
                    ->description(fn (LotChange $r) => $r->lot_id . ($r->lot?->year ? ' · ' . $r->lot->year : ''))
                    ->url(fn (LotChange $r) => $r->lot ? LotResource::getUrl('view', ['record' => $r->lot_id]) : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('lot_id', 'like', addcslashes(trim($search), '%_\\') . '%')),
                TextColumn::make('event')->label('Событие')
                    ->formatStateUsing(fn (?string $state) => AdminUiLabels::event($state)),
                TextColumn::make('changes')->label('Что изменилось')
                    ->state(fn (LotChange $r) => new HtmlString(collect((array) $r->changes)->map(function ($diff, $field) use ($r) {
                        $label = e(AdminUiLabels::field((string) $field));
                        if (is_array($diff) && (array_key_exists('old', $diff) || array_key_exists('new', $diff))) {
                            return "{$label}: <span class=\"lot-history-old\">" . e(LotFormat::changeValue($field, $diff['old'] ?? null, $r->source))
                                . '</span> → ' . e(LotFormat::changeValue($field, $diff['new'] ?? null, $r->source));
                        }

                        return "{$label}: " . e(LotFormat::changeValue($field, $diff, $r->source));
                    })->implode('<br>')))
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('period')->label('Период')
                    ->options(self::PERIODS)
                    ->default('7')
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->where('recorded_at', '>=', now()->subDays((int) $data['value']))
                        : $query),
                SelectFilter::make('event')->label('Событие')
                    ->options(collect(self::EVENTS)->mapWithKeys(fn ($e) => [$e => AdminUiLabels::event($e)])->all()),
                SelectFilter::make('field')->label('Поле')
                    ->options(collect(['price', 'mileage', 'is_active', 'has_accident', 'insurance_count', 'owners_count', 'lien_status', 'seizure_status'])
                        ->mapWithKeys(fn ($f) => [$f => AdminUiLabels::field($f)])->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereRaw('JSON_CONTAINS_PATH(changes, \'one\', ?)', ['$.' . $data['value']])
                        : $query),
                SelectFilter::make('source')->label('Площадка')
                    ->options(fn () => collect(\App\Support\Platforms::all())->mapWithKeys(fn ($s) => [$s => AdminUiLabels::source($s)])->all()),
            ])
            ->searchPlaceholder('ID лота')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLotChanges::route('/'),
        ];
    }
}
