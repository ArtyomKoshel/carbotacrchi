<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Lots\LotFormat;
use App\Models\BotFilterSetting;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\ChatSearchService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

/**
 * «Настройки поиска» (bot_filter_settings): which fields the bot and the
 * search use, how far a single value is widened (tolerance) and what the lot
 * card shows. Each row saves as soon as it changes.
 *
 * Tolerance only turns a single value ("2020 года", "2.0 л") into a range;
 * ranges the client stated are never widened (SearchQuery::withBotTolerance).
 * Percent tolerances are stored as a fraction (0.1 = 10 %).
 */
class SearchSettings extends Page
{
    /** Shown first; the rest is under «Все поля». */
    public const KEY_FIELDS = [
        'year', 'price', 'mileage', 'engine_volume', 'make', 'model', 'fuel', 'transmission', 'body_type', 'drive_type', 'has_accident', 'owners_count',
    ];

    /** Unit of an absolute tolerance */
    public const UNITS = [
        'year' => 'год', 'price' => '₩', 'mileage' => 'км', 'engine_volume' => 'л', 'seat_count' => 'мест',
        'owners_count' => 'влад.', 'insurance_count' => 'случ.', 'first_reg_date' => 'дн', 'listed_at' => 'дн', 'registration_date' => 'дн',
    ];

    protected string $view = 'filament.pages.search-settings';

    protected static ?string $title = 'Настройки поиска';

    protected static ?string $navigationLabel = 'Настройки поиска';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_SEARCH_TEAM;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'search-settings';

    /** @var array<int, array{enabled: bool, display_in_card: bool, tolerance_type: string, tolerance_value: ?string}> */
    public array $rows = [];

    /** Row id saved last, for the «сохранено» hint */
    public ?int $savedId = null;

    public string $testText = '';

    public ?array $testResult = null;

    public static function canAccess(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('bot-filters') ?? false;
    }

    public function mount(): void
    {
        if (BotFilterSetting::count() === 0) {
            Artisan::call('db:seed', ['--class' => 'BotFilterSettingsSeeder', '--force' => true]);
        }
        $this->loadRows();
    }

    private function loadRows(): void
    {
        $this->rows = BotFilterSetting::all()->mapWithKeys(fn (BotFilterSetting $s) => [$s->id => [
            'enabled'         => (bool) $s->enabled,
            'display_in_card' => (bool) $s->display_in_card,
            'tolerance_type'  => $s->tolerance_type ?: 'none',
            // shown in the unit people think in: percent, not a fraction
            'tolerance_value' => $s->tolerance_value === null ? null
                : (string) ($s->tolerance_type === 'percentage' ? round($s->tolerance_value * 100, 2) : $s->tolerance_value),
        ]])->all();
    }

    /** @return array{key: list<BotFilterSetting>, other: list<BotFilterSetting>} */
    public function settings(): array
    {
        $all = BotFilterSetting::orderBy('sort_order')->orderBy('field_name')->get();
        $key = $all->filter(fn ($s) => in_array($s->field_name, self::KEY_FIELDS, true))
            ->sortBy(fn ($s) => array_search($s->field_name, self::KEY_FIELDS, true));

        return ['key' => $key->values()->all(), 'other' => $all->diff($key)->values()->all()];
    }

    public static function supportsTolerance(BotFilterSetting $s): bool
    {
        return in_array($s->dtype, ['int', 'float', 'date'], true);
    }

    /** «±1 год», «±10 %», «точно» */
    public static function toleranceText(BotFilterSetting $s, array $row): string
    {
        if (!self::supportsTolerance($s)) {
            return '—';
        }
        $v = $row['tolerance_value'];
        if ($row['tolerance_type'] === 'none' || $v === null || $v === '' || (float) $v == 0) {
            return 'точное значение';
        }
        if ($row['tolerance_type'] === 'percentage') {
            return '±' . rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',') . ' %';
        }
        $unit = self::UNITS[$s->field_name] ?? '';
        $num = $s->field_name === 'price' ? LotFormat::number((int) $v) : rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');

        return trim("±{$num} {$unit}");
    }

    /** Autosave: any change of rows.{id}.* writes that row. */
    public function updatedRows(mixed $value, string $key): void
    {
        $id = (int) explode('.', $key)[0];
        $setting = BotFilterSetting::find($id);
        $row = $this->rows[$id] ?? null;
        if (!$setting || !$row) {
            return;
        }

        // Same normalisation as the old /admin/bot-filters save
        $type = in_array($row['tolerance_type'], ['none', 'absolute', 'percentage'], true) ? $row['tolerance_type'] : 'none';
        $raw = $row['tolerance_value'];
        $tolerance = null;
        if ($type !== 'none' && is_numeric($raw)) {
            $tolerance = $type === 'percentage' ? max(0, min(1, (float) $raw / 100)) : max(0, (float) $raw);
        }
        if (!self::supportsTolerance($setting)) {
            [$type, $tolerance] = ['none', null];
        }

        $setting->update([
            'enabled'         => (bool) $row['enabled'],
            'display_in_card' => (bool) $row['display_in_card'],
            'tolerance_type'  => $type,
            'tolerance_value' => $tolerance,
        ]);
        $this->savedId = $id;
    }

    /** The old page's preview: how the bot reads a request, with and without tolerance. Calls the AI. */
    public function runTest(): void
    {
        $text = trim($this->testText);
        if ($text === '') {
            $this->testResult = ['error' => 'Введите запрос, как его написал бы клиент.'];

            return;
        }
        $parsed = (new ChatSearchService())->parseAndSearch($text);
        if ($parsed === null) {
            $this->testResult = ['error' => 'Не удалось разобрать запрос.'];

            return;
        }
        $exact = array_filter($parsed['query']->toSearchArray(), fn ($v) => $v !== null && $v !== '' && $v !== []);
        $wide = array_filter($parsed['tolerantQuery']->toSearchArray(), fn ($v) => $v !== null && $v !== '' && $v !== []);

        $this->testResult = [
            'mode'        => ($parsed['isAi'] ?? false) ? 'AI' : 'без AI (запасной разбор)',
            'description' => (string) ($parsed['description'] ?? ''),
            'note'        => (string) ($parsed['toleranceNote'] ?? ''),
            'rows'        => collect(array_keys($exact + $wide))->map(fn ($k) => [
                'key'   => $k,
                'exact' => self::show($exact[$k] ?? null),
                'wide'  => self::show($wide[$k] ?? null),
            ])->all(),
        ];
    }

    private static function show(mixed $v): string
    {
        return match (true) {
            $v === null   => '—',
            is_bool($v)   => $v ? 'да' : 'нет',
            is_array($v)  => implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x, JSON_UNESCAPED_UNICODE), $v)),
            default       => (string) $v,
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Вернуть по умолчанию')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Вернуть настройки поиска по умолчанию?')
                ->modalDescription('Все поля, допуски и состав карточки лота вернутся к начальным значениям (BotFilterSettingsSeeder). Изменения подействуют на поиск бота и сайта сразу.')
                ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
                ->action(function () {
                    BotFilterSetting::truncate();
                    Artisan::call('db:seed', ['--class' => 'BotFilterSettingsSeeder', '--force' => true]);
                    $this->loadRows();
                    Notification::make()->title('Настройки сброшены')->success()->send();
                }),
        ];
    }
}
