<?php

namespace App\Filament\Resources\BotUsers;

use App\Filament\Resources\BotUsers\Pages\ListBotUsers;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «Подборщики»: people using the bot and the mini app (users), with what
 * they search, keep and follow. Read-only; accounts are created by Telegram.
 */
class BotUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'подборщик';

    protected static ?string $pluralModelLabel = 'подборщики';

    protected static ?string $navigationLabel = 'Подборщики';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_SEARCH_TEAM;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'pickers';

    public static function canViewAny(): bool
    {
        return AdminPanelProvider::admin()?->isSuper() ?? false;
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['searches', 'favorites', 'subscriptions']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen', 'desc')
            ->columns([
                // No @username → the name goes on the main line; a blank state would hide the description too.
                TextColumn::make('username')->label('Telegram')
                    ->state(fn (User $r) => $r->username ? '@' . $r->username : ($r->first_name ?: (string) $r->id))
                    ->description(fn (User $r) => $r->username ? $r->first_name : null)
                    ->searchable(['username', 'first_name']),
                TextColumn::make('id')->label('Telegram id')->fontFamily('mono')->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen')->label('Был')->since()->dateTimeTooltip('d.m.Y H:i')->sortable(),
                TextColumn::make('searches_count')->label('Поисков')->alignEnd()->sortable(),
                TextColumn::make('favorites_count')->label('В избранном')->alignEnd()->sortable(),
                TextColumn::make('subscriptions_count')->label('Подписок')->alignEnd()->sortable(),
                TextColumn::make('created_at')->label('С нами с')->date('d.m.Y')->sortable(),
            ])
            ->recordAction('view')
            ->recordActions([
                ViewAction::make()->iconButton()->slideOver()->modalWidth(Width::TwoExtraLarge)
                    ->modalHeading(fn (User $r) => $r->username ? '@' . $r->username : (string) $r->id),
            ])
            ->emptyStateHeading('Никто ещё не открывал бота');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                TextEntry::make('first_name')->label('Имя')->placeholder('—'),
                TextEntry::make('last_seen')->label('Был')->since()->placeholder('—'),
                TextEntry::make('created_at')->label('С нами с')->date('d.m.Y'),
            ]),
            Section::make('Последние поиски')->compact()->schema([
                ViewEntry::make('searches')->hiddenLabel()->view('filament.bot-users.searches'),
            ]),
            Section::make('Избранное')->compact()->schema([
                ViewEntry::make('favorites')->hiddenLabel()->view('filament.bot-users.favorites'),
            ]),
            Section::make('Подписки')->compact()->schema([
                ViewEntry::make('subscriptions')->hiddenLabel()->view('filament.bot-users.subscriptions'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBotUsers::route('/'),
        ];
    }
}
