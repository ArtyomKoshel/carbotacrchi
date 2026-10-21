<?php

namespace App\Filament\Pages;

use App\Models\NotificationEvent;
use App\Models\NotificationLog;
use App\Models\NotificationRecipient;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\Notifications\NotificationState;
use App\Services\Notifications\Notifier;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * «Уведомления» (super only): who gets Telegram alerts from
 * notifications:watch, which types, the thresholds, and what was sent.
 */
class Notifications extends Page implements HasTable
{
    use InteractsWithTable;

    /** Private chat / group id, or a public @channel (same rule as the old page). */
    private const CHAT_ID_REGEX = '/^(-?\d{3,20}|@[A-Za-z0-9_]{5,32})$/';

    protected string $view = 'filament.pages.notifications';

    protected static ?string $title = 'Уведомления';

    protected static ?string $navigationLabel = 'Уведомления';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedBell;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_SYSTEM;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'notifications';

    /** @var array<string, mixed> */
    public ?array $thresholds = [];

    public static function canAccess(): bool
    {
        return AdminPanelProvider::admin()?->isSuper() ?? false;
    }

    public function mount(): void
    {
        $this->thresholdsForm->fill(NotificationSetting::thresholds());
    }

    /** @return array<string, string> */
    private static function types(): array
    {
        return collect(config('notifications.types'))->map(fn (array $t) => $t['label'])->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(NotificationRecipient::query())
            ->defaultSort('id')
            ->heading('Получатели')
            ->description('Бот может писать только тем, кто хоть раз нажал /start. Личный chat id совпадает с Telegram id пользователя.')
            ->columns([
                TextColumn::make('chat_id')->label('Chat id')->fontFamily('mono')->copyable(),
                TextColumn::make('label')->label('Кто')->placeholder('—'),
                TextColumn::make('types')->label('Типы')
                    ->state(fn (NotificationRecipient $r) => count($r->types ?? []) . ' из ' . count(self::types()))
                    ->tooltip(fn (NotificationRecipient $r) => collect($r->types ?? [])->map(fn ($t) => self::types()[$t] ?? $t)->implode(', ')),
                TextColumn::make('enabled')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'получает' : 'выключен')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Добавить получателя')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalHeading('Новый получатель')
                    ->schema([
                        Select::make('user_id')->label('Пользователь бота')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => User::whereNotNull('username')
                                ->where(fn ($q) => $q->where('username', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%"))
                                ->orderByDesc('last_seen')->limit(30)->get()
                                ->mapWithKeys(fn (User $u) => [$u->id => '@' . $u->username . ($u->first_name ? " ({$u->first_name})" : '')])->all())
                            ->getOptionLabelUsing(fn ($value) => ($u = User::find($value)) ? '@' . $u->username : $value)
                            ->helperText('Или укажите chat id ниже.')
                            ->live(),
                        TextInput::make('chat_id')->label('Chat id')
                            ->regex(self::CHAT_ID_REGEX)
                            ->validationMessages(['regex' => 'Число (для групп с минусом) или @channel'])
                            ->required(fn (Get $get) => blank($get('user_id')))
                            ->visible(fn (Get $get) => blank($get('user_id'))),
                        TextInput::make('label')->label('Подпись')->maxLength(100),
                    ])
                    ->using(function (array $data) {
                        $user = !empty($data['user_id']) ? User::find($data['user_id']) : null;
                        $chatId = $user ? (string) $user->id : trim((string) $data['chat_id']);
                        if (NotificationRecipient::where('chat_id', $chatId)->exists()) {
                            Notification::make()->title("Получатель {$chatId} уже есть")->warning()->send();
                            $this->halt();
                        }

                        return NotificationRecipient::create([
                            'chat_id' => $chatId,
                            'label'   => ($data['label'] ?? null) ?: ($user?->username ? '@' . $user->username : null),
                            'user_id' => $user?->id,
                            'enabled' => true,
                            'types'   => NotificationRecipient::defaultTypes(),
                        ]);
                    })
                    ->successNotificationTitle('Получатель добавлен. Отправьте тестовое сообщение.'),
            ])
            ->recordActions([
                Action::make('test')
                    ->label('Отправить тест')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->iconButton()
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (NotificationRecipient $r) => "Отправить тестовое сообщение в {$r->chat_id}?")
                    ->modalDescription('Бот пришлёт короткое сообщение «Тестовое уведомление CarBot».')
                    ->action(function (NotificationRecipient $r, Notifier $notifier) {
                        $text = "🔔 <b>Тестовое уведомление CarBot</b>\nУведомления настроены: " . count($r->types ?? []) . ' тип(ов)'
                            . ($r->enabled ? '' : ', получатель выключен')
                            . "\nОтправил: " . e(AdminPanelProvider::admin()?->username ?? 'admin');
                        $res = $notifier->deliver((string) $r->chat_id, $text, 'test');
                        $res['ok']
                            ? Notification::make()->title("Отправлено в {$r->chat_id}")->success()->send()
                            : Notification::make()->title("Не удалось отправить в {$r->chat_id}")->body($res['error'] ?? '')->danger()->send();
                    }),
                EditAction::make()
                    ->iconButton()
                    ->modalHeading(fn (NotificationRecipient $r) => "Получатель {$r->chat_id}")
                    ->schema([
                        TextInput::make('label')->label('Подпись')->maxLength(100),
                        Toggle::make('enabled')->label('Получает уведомления'),
                        CheckboxList::make('types')->label('Типы')->options(self::types())->columns(2),
                    ]),
                DeleteAction::make()
                    ->iconButton()
                    ->color('gray')
                    ->modalHeading(fn (NotificationRecipient $r) => "Удалить получателя {$r->chat_id}?")
                    ->modalDescription('Он перестанет получать уведомления. История отправок останется.'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Получателей нет: уведомления никуда не уходят');
    }

    public function thresholdsForm(Schema $schema): Schema
    {
        $defs = config('notifications.thresholds');

        return $schema
            ->statePath('thresholds')
            ->components([
                Section::make('Пороги')->compact()->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema(collect($defs)->map(fn (array $d, string $key) => TextInput::make($key)
                        ->label($d['label'])
                        ->suffix($d['unit'])
                        ->numeric()->minValue(0)->maxValue(100000)->required())->values()->all()),
                    Actions::make([
                        Action::make('saveThresholds')->label('Сохранить пороги')->action(fn () => $this->saveThresholds()),
                    ]),
                ]),
            ]);
    }

    public function saveThresholds(): void
    {
        foreach ($this->thresholdsForm->getState() as $key => $value) {
            if (array_key_exists($key, config('notifications.thresholds'))) {
                NotificationSetting::updateOrCreate(['key' => $key], ['value' => (string) (float) $value]);
            }
        }
        Notification::make()->title('Пороги сохранены')->success()->send();
    }

    /** @return array{status: array, dbDown: mixed, events: \Illuminate\Support\Collection, log: \Illuminate\Support\Collection, types: array} */
    public function overview(): array
    {
        $state = app(NotificationState::class);
        $safe = function (\Closure $fn) {
            try {
                return $fn();
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'status' => (array) ($safe(fn () => $state->get('last_status', [])) ?? []),
            'dbDown' => $safe(fn () => $state->get('db_down')),
            'events' => NotificationEvent::where('status', 'open')->orderByDesc('opened_at')->get(),
            'log'    => NotificationLog::orderByDesc('id')->limit(40)->get(),
            'types'  => self::types(),
        ];
    }
}
