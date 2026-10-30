<?php

namespace App\Filament\Resources\Lots\Tables;

use App\Filament\Resources\Lots\LotActions;
use App\Filament\Resources\Lots\LotFormat;
use App\Models\Lot;
use App\Support\AdminUiLabels;
use App\Support\LotPhotos;
use App\Support\Taxonomy\TaxonomyCatalog;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LotsTable
{
    /** Values present on prod (2026-10); labels come from TaxonomyCatalog. */
    private const FUELS = ['gasoline', 'diesel', 'hybrid', 'electric', 'lpg', 'hydrogen'];
    private const BODY_TYPES = ['sedan', 'suv', 'kei', 'coupe'];
    private const TRANSMISSIONS = ['automatic', 'manual', 'cvt'];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns(self::columns())
            ->filters(self::filters())
            ->filtersFormColumns(2)
            ->filtersFormWidth(Width::ThreeExtraLarge)
            // Newest listings first; listed_at has an index, so the first page is cheap.
            // Only without a column sort, so ORDER BY price stays on the price index.
            ->defaultSort(fn (Builder $query, HasTable $livewire) => $livewire->getTableSortColumn()
                ? $query
                : $query->orderByDesc('listed_at')->orderByDesc('id'))
            ->searchPlaceholder('ID, VIN, госномер, модель (как в справочнике или у Encar)')
            // Row click opens the slide-over (list + side panel), not the lot page
            ->recordUrl(null)
            ->recordAction('view')
            ->recordActions([
                ViewAction::make()
                    ->label('Открыть')
                    ->iconButton()
                    ->slideOver()
                    ->modalWidth(Width::FourExtraLarge)
                    ->modalHeading(fn (Lot $record) => LotFormat::title($record))
                    ->modalDescription(fn (Lot $record) => LotFormat::subtitle($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    LotActions::bulkReparse(),
                ])->label('Действия'),
            ])
            ->maxSelectableRecords(LotActions::bulkLimit())
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Лотов не найдено')
            ->emptyStateDescription('Измените фильтры или поиск.');
    }

    /** @return array<\Filament\Tables\Columns\Column> */
    private static function columns(): array
    {
        return [
            ImageColumn::make('image_url')
                ->label('Фото')
                ->state(fn (Lot $record) => $record->image_url ?: (LotPhotos::urls($record->getAttributes())[0] ?? null))
                ->imageWidth(72)
                ->imageHeight(54)
                ->extraImgAttributes(['loading' => 'lazy', 'class' => 'lot-thumb', 'alt' => ''])
                ->toggleable(),

            TextColumn::make('title')
                ->label('Автомобиль')
                ->state(fn (Lot $record) => LotFormat::title($record))
                ->description(fn (Lot $record) => LotFormat::subtitle($record))
                ->weight('medium')
                ->wrap()
                ->searchable(query: fn (Builder $query, string $search) => self::search($query, $search)),

            TextColumn::make('year')
                ->label('Год')
                ->sortable(),

            TextColumn::make('mileage')
                ->label('Пробег')
                ->formatStateUsing(fn ($state) => LotFormat::km($state))
                ->alignEnd()
                ->sortable(),

            TextColumn::make('price')
                ->label('Цена')
                ->formatStateUsing(fn ($state, $record) => LotFormat::money($state, $record->currency))
                ->alignEnd()
                ->sortable(),

            TextColumn::make('source')
                ->label('Площадка')
                ->formatStateUsing(fn (?string $state) => AdminUiLabels::source($state))
                ->toggleable(),

            TextColumn::make('is_active')
                ->label('Статус')
                ->badge()
                ->formatStateUsing(fn ($state) => $state ? 'активен' : 'снят')
                ->color(fn ($state) => $state ? 'success' : 'gray'),

            TextColumn::make('parsed_at')
                ->label('Обновлён')
                ->since()
                ->dateTimeTooltip('d.m.Y H:i')
                ->sortable()
                ->toggleable(),

            // Less used: hidden until switched on in the column manager
            TextColumn::make('fuel')->label('Топливо')
                ->formatStateUsing(fn (?string $state) => LotFormat::taxonomy('fuel', $state))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('transmission')->label('КПП')
                ->formatStateUsing(fn (?string $state) => LotFormat::taxonomy('transmission', $state))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('body_type')->label('Кузов')
                ->formatStateUsing(fn (?string $state) => LotFormat::taxonomy('body_type', $state))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('drive_type')->label('Привод')
                ->formatStateUsing(fn (?string $state) => LotFormat::taxonomy('drive_type', $state))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('engine_volume')->label('Объём')
                ->formatStateUsing(fn ($state) => LotFormat::liters($state))
                ->alignEnd()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('has_accident')->label('ДТП')
                ->formatStateUsing(fn ($state) => LotFormat::yesNo($state))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('owners_count')->label('Владельцев')
                ->alignEnd()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('vin')->label('VIN')
                ->fontFamily('mono')
                ->copyable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('plate_number')->label('Госномер')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('location')->label('Регион')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('listed_at')->label('Опубликован')
                ->date('d.m.Y')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('created_at')->label('Впервые найден')
                ->since()
                ->dateTimeTooltip('d.m.Y H:i')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /** Search box: id prefix (PK range), VIN, plate, the catalog's model name or Encar's Korean one. */
    public static function search(Builder $query, string $search): Builder
    {
        $search = trim($search);
        $like   = '%' . addcslashes($search, '%_\\') . '%';

        return $query->where(fn (Builder $q) => $q
            ->where('id', 'like', addcslashes($search, '%_\\') . '%')
            ->orWhere('vin', 'like', $like)
            ->orWhere('plate_number', 'like', $like)
            ->orWhereIn('ref_model_id', DB::table('ref_models')->where('name', 'like', $like)->select('id'))
            ->orWhere('model', 'like', $like));
    }

    /** @return array<\Filament\Tables\Filters\BaseFilter> */
    private static function filters(): array
    {
        return [
            SelectFilter::make('source')
                ->label('Площадка')
                ->options(fn () => collect(config('auction.sources', ['encar']))
                    ->mapWithKeys(fn (string $s) => [$s => AdminUiLabels::source($s)])
                    ->all()),

            TernaryFilter::make('is_active')
                ->label('Статус')
                ->placeholder('Все')
                ->trueLabel('Активные')
                ->falseLabel('Снятые'),

            self::makeModelFilter(),

            self::rangeFilter('year', 'Год', 'Год', fn ($v) => (string) $v),
            self::rangeFilter('price', 'Цена, ₩', 'Цена', fn ($v) => LotFormat::won($v)),
            self::rangeFilter('mileage', 'Пробег, км', 'Пробег', fn ($v) => LotFormat::km($v)),

            SelectFilter::make('fuel')
                ->label('Топливо')
                ->multiple()
                ->options(self::taxonomyOptions('fuel', self::FUELS)),
            SelectFilter::make('body_type')
                ->label('Кузов')
                ->multiple()
                ->options(self::taxonomyOptions('body_type', self::BODY_TYPES)),
            SelectFilter::make('transmission')
                ->label('КПП')
                ->multiple()
                ->options(self::taxonomyOptions('transmission', self::TRANSMISSIONS)),

            TernaryFilter::make('has_accident')
                ->label('ДТП')
                ->placeholder('Неважно')
                ->trueLabel('Были ДТП')
                ->falseLabel('Без ДТП'),
        ];
    }

    /**
     * Make → model → generation of the reference catalog; the lower levels load for the chosen
     * make / model only. "Не разобрано" picks the lots the catalog could not place.
     */
    private static function makeModelFilter(): Filter
    {
        return Filter::make('make_model')
            ->schema([
                Select::make('ref_make_id')
                    ->label('Марка')
                    ->options(fn () => ['none' => LotFormat::UNPLACED] + self::makes())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('ref_model_id', null);
                        $set('ref_generation_id', null);
                    }),
                Select::make('ref_model_id')
                    ->label('Модель')
                    ->options(fn (Get $get) => is_numeric($get('ref_make_id')) ? self::models((int) $get('ref_make_id')) : [])
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('ref_generation_id', null))
                    ->disabled(fn (Get $get) => !is_numeric($get('ref_make_id'))),
                Select::make('ref_generation_id')
                    ->label('Поколение')
                    ->options(fn (Get $get) => filled($get('ref_model_id')) ? self::generations((int) $get('ref_model_id')) : [])
                    ->searchable()
                    ->disabled(fn (Get $get) => blank($get('ref_model_id'))),
            ])
            ->columns(3)
            ->columnSpanFull()
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['ref_make_id'] ?? null, fn (Builder $q, $v) => $v === 'none'
                    ? $q->whereNull('ref_model_id')
                    : $q->where('ref_make_id', (int) $v))
                ->when($data['ref_model_id'] ?? null, fn (Builder $q, $v) => $q->where('ref_model_id', (int) $v))
                ->when($data['ref_generation_id'] ?? null, fn (Builder $q, $v) => $q->where('ref_generation_id', (int) $v)))
            ->indicateUsing(function (array $data): array {
                $indicators = [];
                if (filled($make = $data['ref_make_id'] ?? null)) {
                    $name = $make === 'none' ? LotFormat::UNPLACED : (self::makes()[(int) $make] ?? $make);
                    $indicators[] = Indicator::make("Марка: {$name}")->removeField('ref_make_id');
                }
                if (filled($model = $data['ref_model_id'] ?? null)) {
                    $name = DB::table('ref_models')->where('id', (int) $model)->value('name') ?? $model;
                    $indicators[] = Indicator::make("Модель: {$name}")->removeField('ref_model_id');
                }
                if (filled($generation = $data['ref_generation_id'] ?? null)) {
                    $name = DB::table('ref_generations')->where('id', (int) $generation)->value('code') ?? $generation;
                    $indicators[] = Indicator::make("Поколение: {$name}")->removeField('ref_generation_id');
                }

                return $indicators;
            });
    }

    /** @return array<int, string> */
    private static function makes(): array
    {
        return DB::table('ref_makes')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private static function models(int $makeId): array
    {
        return DB::table('ref_models')->where('make_id', $makeId)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private static function generations(int $modelId): array
    {
        return DB::table('ref_generations')->where('model_id', $modelId)->orderBy('year_from')
            ->get(['id', 'code', 'year_from', 'year_to'])
            ->mapWithKeys(fn ($g) => [$g->id => trim($g->code . ' · ' . LotFormat::years($g->year_from, $g->year_to), ' ·')])
            ->all();
    }

    /** From/to numeric filter on one column, with one chip per bound. */
    private static function rangeFilter(string $column, string $label, string $chip, \Closure $format): Filter
    {
        return Filter::make($column)
            ->schema([
                TextInput::make('from')->label("{$label} от")->numeric()->minValue(0),
                TextInput::make('to')->label("{$label} до")->numeric()->minValue(0),
            ])
            ->columns(2)
            ->query(fn (Builder $query, array $data) => $query
                ->when(is_numeric($data['from'] ?? null), fn (Builder $q) => $q->where($column, '>=', (int) $data['from']))
                ->when(is_numeric($data['to'] ?? null), fn (Builder $q) => $q->where($column, '<=', (int) $data['to'])))
            ->indicateUsing(function (array $data) use ($chip, $format): array {
                $indicators = [];
                if (is_numeric($data['from'] ?? null)) {
                    $indicators[] = Indicator::make("{$chip} от " . $format((int) $data['from']))->removeField('from');
                }
                if (is_numeric($data['to'] ?? null)) {
                    $indicators[] = Indicator::make("{$chip} до " . $format((int) $data['to']))->removeField('to');
                }

                return $indicators;
            });
    }

    /** @return array<string, string> */
    private static function taxonomyOptions(string $field, array $values): array
    {
        $labels = TaxonomyCatalog::labelMap($field);

        return collect($values)->mapWithKeys(fn (string $v) => [$v => $labels[$v] ?? $v])->all();
    }
}
