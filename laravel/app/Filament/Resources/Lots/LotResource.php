<?php

namespace App\Filament\Resources\Lots;

use App\Filament\Resources\Lots\Pages\ListLots;
use App\Filament\Resources\Lots\Pages\ViewLot;
use App\Filament\Resources\Lots\Schemas\LotInfolist;
use App\Filament\Resources\Lots\Tables\LotsTable;
use App\Models\Lot;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Lots, read-mostly: the parser owns the data, the panel only re-queues it. */
class LotResource extends Resource
{
    protected static ?string $model = Lot::class;

    protected static ?string $modelLabel = 'лот';

    protected static ?string $pluralModelLabel = 'лоты';

    protected static ?string $navigationLabel = 'Лоты';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_WORK;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'lots';

    protected static int $globalSearchResultsLimit = 8;

    /** The old admin's «Поиск лотов» page permission. */
    public static function canViewAny(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('lots-browse') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return LotInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LotsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLots::route('/'),
            'view'  => ViewLot::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'vin', 'plate_number', 'model'];
    }

    /** Same matching as the table search box. */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        LotsTable::search($query, $search);
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Lot $record */
        return LotFormat::title($record) . ($record->year ? ", {$record->year}" : '');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Lot $record */
        return [
            'ID'   => $record->id,
            'Цена' => LotFormat::won($record->price),
        ];
    }
}
