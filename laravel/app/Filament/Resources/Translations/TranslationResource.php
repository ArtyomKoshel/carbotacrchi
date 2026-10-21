<?php

namespace App\Filament\Resources\Translations;

use App\Filament\Resources\Lots\LotFormat;
use App\Filament\Resources\Translations\Pages\ListTranslations;
use App\Models\Translation;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\TranslationCatalog;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * «Переводы»: the whole KR→EN dictionary (translations). Editing pushes the
 * new value to the lots that carry the old one; see TranslationCatalog.
 */
class TranslationResource extends Resource
{
    protected static ?string $model = Translation::class;

    protected static ?string $modelLabel = 'перевод';

    protected static ?string $pluralModelLabel = 'переводы';

    protected static ?string $navigationLabel = 'Переводы';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_DATA;

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('taxonomy') ?? false;
    }

    public static function table(Table $table): Table
    {
        $catalog = app(TranslationCatalog::class);

        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('category')->label('Поле')
                    ->formatStateUsing(fn (?string $state) => TranslationCatalog::label($state))
                    ->sortable(),
                TextColumn::make('kr')->label('По-корейски')->searchable()->sortable()->copyable(),
                TextColumn::make('en')->label('По-английски')->searchable()->sortable()->weight('medium'),
                TextColumn::make('ru')->label('По-русски')->searchable()->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')->label('Статус')->badge()
                    ->formatStateUsing(fn (?string $state) => TranslationCatalog::SOURCES[$state] ?? $state)
                    ->color(fn (?string $state) => $state === 'ai' ? 'warning' : 'success'),
                TextColumn::make('updated_at')->label('Изменён')->since()->dateTimeTooltip('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')->label('Поле')->options(TranslationCatalog::LABELS),
                SelectFilter::make('source')->label('Статус')->options(TranslationCatalog::SOURCES),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Добавить перевод')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalHeading('Новый перевод')
                    ->schema([
                        Select::make('category')->label('Поле')->options(TranslationCatalog::LABELS)->required(),
                        TextInput::make('kr')->label('По-корейски')->required()->maxLength(200),
                        TextInput::make('en')->label('По-английски')->required()->maxLength(200),
                        TextInput::make('ru')->label('По-русски')->maxLength(200),
                    ])
                    ->using(function (array $data) use ($catalog) {
                        [$t, $n] = $catalog->create($data['category'], trim($data['kr']), trim($data['en']), ($data['ru'] ?? null) ?: null);
                        Notification::make()->title('Перевод добавлен')->body('Заполнено лотов: ' . LotFormat::number($n))->success()->send();

                        return $t;
                    })
                    ->successNotification(null),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Изменить')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->color('gray')
                    ->modalHeading(fn (Translation $record) => 'Перевод: ' . $record->kr)
                    ->modalDescription(fn (Translation $record) => 'Лотов, где поле «' . TranslationCatalog::label($record->category)
                        . '» сейчас с этим переводом или пустое: ' . LotFormat::number($catalog->affected($record)) . '. Они получат новое значение.')
                    ->fillForm(fn (Translation $record) => ['en' => $record->en, 'ru' => $record->ru])
                    ->schema([
                        TextInput::make('en')->label('По-английски')->required()->maxLength(200),
                        TextInput::make('ru')->label('По-русски')->maxLength(200),
                    ])
                    ->action(function (Translation $record, array $data) use ($catalog) {
                        $n = $catalog->update($record, trim($data['en']), ($data['ru'] ?? null) ?: null);
                        Notification::make()->title('Сохранено')->body('Обновлено лотов: ' . LotFormat::number($n))->success()->send();
                    }),
                Action::make('delete')
                    ->label('Удалить')
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Translation $record) => 'Удалить перевод «' . $record->en . '»?')
                    ->modalDescription(fn (Translation $record) => TranslationCatalog::keepsKorean($record->category)
                        ? 'Лотов, где это поле станет пустым: ' . LotFormat::number($catalog->affected($record)) . '. Следующий translate:run переведёт значение заново.'
                        : 'В лотах цвет уже записан по-английски и останется как есть.')
                    ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
                    ->action(function (Translation $record) use ($catalog) {
                        $n = $catalog->reject($record);
                        Notification::make()->title('Перевод удалён')->body('Очищено лотов: ' . LotFormat::number($n))->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('accept')
                    ->label('Отметить проверенными')
                    ->icon(Heroicon::OutlinedCheck)
                    ->requiresConfirmation()
                    ->modalDescription('Переводы останутся как есть, только получат статус «проверен». В лотах ничего не меняется.')
                    ->action(function (Collection $records) {
                        $n = Translation::whereIn('id', $records->pluck('id'))->where('source', 'ai')->update(['source' => 'manual', 'updated_at' => now()]);
                        Notification::make()->title('Отмечено: ' . $n)->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTranslations::route('/'),
        ];
    }
}
