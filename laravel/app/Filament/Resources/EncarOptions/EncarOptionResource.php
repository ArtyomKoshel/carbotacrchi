<?php

namespace App\Filament\Resources\EncarOptions;

use App\Filament\Resources\EncarOptions\Pages\ListEncarOptions;
use App\Models\EncarOptionCatalog;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * «Опции Encar»: names of option codes (encar_option_catalog). Codes and their
 * bits in lots.options_mask come from options:import-encar and are not edited.
 */
class EncarOptionResource extends Resource
{
    public const CATEGORIES = [
        'exterior'    => 'Экстерьер',
        'interior'    => 'Салон',
        'safety'      => 'Безопасность',
        'convenience' => 'Удобство',
    ];

    protected static ?string $model = EncarOptionCatalog::class;

    protected static ?string $modelLabel = 'опция';

    protected static ?string $pluralModelLabel = 'опции Encar';

    protected static ?string $navigationLabel = 'Опции Encar';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_DATA;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'encar-options';

    public static function canViewAny(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('taxonomy') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name_ru')->label('По-русски')->required()->maxLength(255)
                ->helperText('Так опция называется в поиске и карточке лота.'),
            TextInput::make('name_en')->label('По-английски')->maxLength(255),
            TextInput::make('name_kr')->label('По-корейски (как на Encar)')->maxLength(255),
            Select::make('category')->label('Группа')->options(self::CATEGORIES),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                // Encar answers 404 for the stored icon URLs (2026-10): hidden until they are refreshed
                ImageColumn::make('icon_url')->label('Иконка')->imageSize(28)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('code')->label('Код')->fontFamily('mono')->sortable()->searchable(),
                TextColumn::make('name_ru')->label('По-русски')->weight('medium')->searchable()->sortable(),
                TextColumn::make('name_en')->label('По-английски')->searchable(),
                TextColumn::make('name_kr')->label('По-корейски')->searchable()->toggleable(),
                TextColumn::make('category')->label('Группа')
                    ->formatStateUsing(fn (?string $state) => self::CATEGORIES[$state] ?? $state),
                TextColumn::make('bit')->label('Бит')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')->label('Группа')->options(self::CATEGORIES),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->modalHeading(fn (EncarOptionCatalog $record) => "Опция {$record->code}")
                    // the search and lot cards read the catalog from a 24 h cache
                    ->after(fn () => EncarOptionCatalog::flushCache()),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEncarOptions::route('/'),
        ];
    }
}
