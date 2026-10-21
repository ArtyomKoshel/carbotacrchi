<?php

namespace App\Filament\Resources\AdminUsers;

use App\Filament\Resources\AdminUsers\Pages\ListAdminUsers;
use App\Models\AdminPagePermission;
use App\Models\AdminUser;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * «Доступ к админке» (super only): admin_users and what the «limited» role
 * may open (admin_page_permissions, one set for all limited admins).
 */
class AdminUserResource extends Resource
{
    public const ROLES = [
        'super'   => 'Полный доступ',
        'limited' => 'Ограниченный',
    ];

    /** Page keys as the panel names those sections now */
    public const PAGE_LABELS = [
        'dashboard'       => 'Обзор',
        'lots-browse'     => 'Лоты',
        'lots'            => 'Перепарсинг лотов',
        'jobs'            => 'Площадки: прогоны и запуск',
        'schedules'       => 'Площадки: расписание',
        'filters'         => 'Площадки: правила отбора',
        'filter-skip-log' => 'Площадки: лог пропусков',
        'fields'          => 'Поля и покрытие',
        'taxonomy'        => 'Переводы и опции',
        'changes'         => 'Изменения лотов',
        'bot-filters'     => 'Настройки поиска',
        'logs'            => 'Логи',
    ];

    protected static ?string $model = AdminUser::class;

    protected static ?string $modelLabel = 'администратор';

    protected static ?string $pluralModelLabel = 'администраторы';

    protected static ?string $navigationLabel = 'Доступ к админке';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_SEARCH_TEAM;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'admin-users';

    public static function canViewAny(): bool
    {
        return AdminPanelProvider::admin()?->isSuper() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        // never yourself, never the last super admin
        return static::canViewAny()
            && $record->getKey() !== AdminPanelProvider::admin()?->getKey()
            && !($record->role === 'super' && AdminUser::where('role', 'super')->count() <= 1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('username')
            ->columns([
                TextColumn::make('username')->label('Логин')->weight('medium')->searchable()
                    ->description(fn (AdminUser $r) => $r->getKey() === AdminPanelProvider::admin()?->getKey() ? 'это вы' : null),
                TextColumn::make('role')->label('Роль')
                    ->formatStateUsing(fn (?string $state) => self::ROLES[$state] ?? $state),
                TextColumn::make('created_at')->label('Создан')->date('d.m.Y'),
            ])
            ->headerActions([
                Action::make('permissions')
                    ->label('Что видят ограниченные')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('gray')
                    ->modalHeading('Разделы для роли «Ограниченный»')
                    ->modalSubmitActionLabel('Сохранить')
                    ->modalDescription('Один набор на всех ограниченных администраторов. Доступ к администраторам и уведомлениям есть только у полного доступа.')
                    ->fillForm(fn () => ['pages' => AdminPagePermission::where('enabled', true)->pluck('page_key')->all()])
                    ->schema([
                        CheckboxList::make('pages')->hiddenLabel()->options(self::PAGE_LABELS)->columns(2)->bulkToggleable(),
                    ])
                    ->action(function (array $data) {
                        foreach (array_keys(self::PAGE_LABELS) as $key) {
                            AdminPagePermission::updateOrCreate(
                                ['page_key' => $key],
                                ['enabled' => in_array($key, $data['pages'] ?? [], true), 'label' => AdminPagePermission::PAGES[$key] ?? self::PAGE_LABELS[$key]],
                            );
                        }
                        Notification::make()->title('Права сохранены')->body('Действуют со следующего запроса ограниченных администраторов.')->success()->send();
                    }),
                CreateAction::make()
                    ->label('Добавить')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalHeading('Новый администратор')
                    ->schema([
                        TextInput::make('username')->label('Логин')->required()->maxLength(64)
                            ->unique(table: 'admin_users', column: 'username', modifyRuleUsing: fn (Unique $rule) => $rule)
                            ->validationMessages(['unique' => 'Такой логин уже есть']),
                        TextInput::make('password')->label('Пароль')->password()->revealable()->required()->minLength(6)->maxLength(128),
                        Radio::make('role')->label('Роль')->options(self::ROLES)->default('limited')->required(),
                    ]),
            ])
            ->recordActions([
                Action::make('password')
                    ->label('Сменить пароль')
                    ->icon(Heroicon::OutlinedKey)
                    ->iconButton()
                    ->color('gray')
                    ->modalHeading(fn (AdminUser $r) => "Новый пароль для {$r->username}")
                    ->schema([
                        TextInput::make('password')->label('Пароль')->password()->revealable()->required()->minLength(6)->maxLength(128),
                    ])
                    ->action(function (AdminUser $r, array $data) {
                        $r->password = $data['password'];
                        $r->save();
                        Notification::make()->title("Пароль для {$r->username} обновлён")->success()->send();
                    }),
                Action::make('role')
                    ->label('Изменить роль')
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->iconButton()
                    ->color('gray')
                    ->visible(fn (AdminUser $r) => static::canDelete($r))
                    ->modalHeading(fn (AdminUser $r) => "Роль {$r->username}")
                    ->fillForm(fn (AdminUser $r) => ['role' => $r->role])
                    ->schema([Radio::make('role')->label('Роль')->options(self::ROLES)->required()])
                    ->action(function (AdminUser $r, array $data) {
                        $r->update(['role' => $data['role']]);
                        Notification::make()->title('Роль изменена')->success()->send();
                    }),
                DeleteAction::make()
                    ->iconButton()
                    ->color('gray')
                    ->modalHeading(fn (AdminUser $r) => "Удалить {$r->username}?")
                    ->modalDescription('Администратор больше не сможет войти. Открытые сессии закончатся при следующем запросе.'),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdminUsers::route('/'),
        ];
    }
}
