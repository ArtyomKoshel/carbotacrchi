<?php

namespace App\Filament\Resources\RefVariants;

use App\Catalog\CatalogLinker;
use App\Filament\Resources\Lots\LotFormat;
use App\Filament\Resources\RefVariants\Pages\ListRefVariants;
use App\Models\RefVariant;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * «Справочник: разбор»: Encar variants on the reference catalog (app/Catalog). What the rules
 * could not read waits here; «Разметить» places a variant by hand — its lots move at once and
 * catalog:remap leaves it alone; «Вернуть правилам» undoes that. Only makes the catalog has.
 */
class RefVariantResource extends Resource
{
    public const STATUSES = ['unmatched' => 'Не разобран', 'no_engine' => 'Без двигателя', 'matched' => 'Разобран'];

    private const FUELS = ['gasoline' => 'Бензин', 'diesel' => 'Дизель', 'hybrid' => 'Гибрид', 'electric' => 'Электро'];

    private const DRIVES = ['awd' => 'Полный', 'rwd' => 'Задний', 'fwd' => 'Передний'];

    protected static ?string $model = RefVariant::class;

    protected static ?string $modelLabel = 'вариант';

    protected static ?string $pluralModelLabel = 'варианты';

    protected static ?string $navigationLabel = 'Справочник: разбор';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_DATA;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'catalog-variants';

    /** @var array<string, array<int, string>> table → id → name, for one page of rows */
    private static array $names = [];

    public static function canViewAny(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('taxonomy') ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->whereNotNull('make_id'))
            ->defaultSort('lots', 'desc')
            ->columns([
                TextColumn::make('model')->label('Encar: модель')->searchable()
                    ->description(fn (RefVariant $r) => $r->make),
                TextColumn::make('badge')->label('Бейдж')->searchable()->wrap()
                    ->description(fn (RefVariant $r) => $r->trim && $r->trim !== $r->badge ? 'BadgeDetail: ' . $r->trim : null),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ['matched' => 'success', 'no_engine' => 'warning'][$state] ?? 'danger'),
                IconColumn::make('manual')->label('Вручную')->boolean()->trueIcon(Heroicon::OutlinedHandRaised)->falseIcon(null),
                TextColumn::make('place')->label('В справочнике')->wrap()
                    ->getStateUsing(fn (RefVariant $r) => self::place($r) ?: '—'),
                TextColumn::make('leftover')->label('Не понято')->wrap()->placeholder('—')
                    ->getStateUsing(fn (RefVariant $r) => $r->leftover ? implode(' · ', $r->leftover) : ($r->status === 'unmatched' ? self::reason($r->reason) : null)),
                TextColumn::make('lots')->label('Лотов')->numeric()->sortable()
                    ->formatStateUsing(fn ($state) => LotFormat::number((int) $state)),
            ])
            ->filters([
                SelectFilter::make('make_id')->label('Марка')
                    ->options(fn () => DB::table('ref_makes')->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('assign')
                    ->label('Разметить')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(fn (RefVariant $r) => trim("{$r->model} · {$r->badge}"))
                    ->modalDescription(fn (RefVariant $r) => 'Лотов: ' . LotFormat::number($r->lots)
                        . '. Привод и топливо — только если они точно известны: пустое поле значит «не указано».')
                    ->fillForm(fn (RefVariant $r) => [
                        'generation_id' => $r->generation_id,
                        'modification' => self::name('ref_modifications', $r->modification_id, 'code'),
                        'fuel' => DB::table('ref_modifications')->where('id', $r->modification_id)->value('fuel'),
                        'drive' => DB::table('ref_modifications')->where('id', $r->modification_id)->value('drive'),
                        'complectation' => self::name('ref_complectations', $r->complectation_id),
                    ])
                    ->schema(fn (RefVariant $r) => [
                        Select::make('generation_id')->label('Модель и поколение')->required()->searchable()
                            ->options(self::generations((int) $r->make_id)),
                        TextInput::make('modification')->label('Модификация (как пишет производитель)')->maxLength(80)
                            ->placeholder('530i xDrive')
                            ->datalist(fn () => DB::table('ref_modifications as d')->join('ref_generations as g', 'g.id', '=', 'd.generation_id')
                                ->join('ref_models as m', 'm.id', '=', 'g.model_id')->where('m.make_id', $r->make_id)
                                ->distinct()->orderBy('d.code')->pluck('d.code')->all()),
                        Select::make('fuel')->label('Топливо')->options(self::FUELS)->placeholder('Не указано'),
                        Select::make('drive')->label('Привод')->options(self::DRIVES)->placeholder('Не указан'),
                        TextInput::make('complectation')->label('Комплектация')->maxLength(100)
                            ->datalist(fn () => DB::table('ref_complectations')->where('make_id', $r->make_id)->orderBy('name')->pluck('name')->all()),
                    ])
                    ->action(function (RefVariant $r, array $data) {
                        $moved = app(CatalogLinker::class)->assign($r->id, (int) $data['generation_id'], $data['modification'] ?? null,
                            $data['fuel'] ?? null, $data['drive'] ?? null, $data['complectation'] ?? null);
                        Notification::make()->title('Размечено')->body('Лотов перенесено: ' . LotFormat::number($moved))->success()->send();
                    }),
                Action::make('release')
                    ->label('Вернуть правилам')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (RefVariant $r) => $r->manual)
                    ->requiresConfirmation()
                    ->modalDescription('Ручная разметка снимется, вариант разберут правила, его лоты перейдут туда же.')
                    ->action(function (RefVariant $r) {
                        $moved = app(CatalogLinker::class)->release($r->id);
                        Notification::make()->title('Вариант разобран правилами')->body('Лотов перенесено: ' . LotFormat::number($moved))->success()->send();
                    }),
            ])
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50);
    }

    /** "5 Series G60 · 530i xDrive · M Sport · Special Edition" */
    private static function place(RefVariant $r): string
    {
        $model = self::name('ref_models', $r->model_id);
        $generation = self::name('ref_generations', $r->generation_id, 'code');
        return implode(' · ', array_filter([
            trim("{$model} {$generation}"),
            self::name('ref_modifications', $r->modification_id, 'code'),
            self::name('ref_complectations', $r->complectation_id),
            ...($r->editions ?? []),
        ]));
    }

    private static function reason(?string $reason): ?string
    {
        return match (true) {
            $reason === null => null,
            $reason === 'Encar names no generation' => 'Encar не указал поколение',
            str_starts_with($reason, 'model ') => 'Модели нет в справочнике: ' . substr($reason, 6, -17),
            str_starts_with($reason, 'generation ') => 'Поколения нет в справочнике: ' . substr($reason, 11, -17),
            default => $reason,
        };
    }

    private static function name(string $table, ?int $id, string $column = 'name'): ?string
    {
        if ($id === null) {
            return null;
        }
        return self::$names["{$table}.{$column}"][$id] ??= DB::table($table)->where('id', $id)->value($column);
    }

    /** @return array<int, string> generation id → "5 Series · G60 (2023–)" */
    private static function generations(int $makeId): array
    {
        return DB::table('ref_generations as g')->join('ref_models as m', 'm.id', '=', 'g.model_id')
            ->where('m.make_id', $makeId)->orderBy('m.name')->orderBy('g.year_from')
            ->get(['g.id', 'm.name', 'g.code', 'g.year_from', 'g.year_to'])
            ->mapWithKeys(fn ($g) => [$g->id => "{$g->name} · {$g->code}" . ($g->year_from ? " ({$g->year_from}–" . ($g->year_to ?? '') . ')' : '')])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRefVariants::route('/'),
        ];
    }
}
