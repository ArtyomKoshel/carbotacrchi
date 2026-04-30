# Plan V4: Bot Filter — Управление фильтрами бота через админку

> Предыдущие планы: PLAN.md (v1 архитектура), PLAN_V2.md (AI-поиск + парсеры), PLAN_V3.md (оставшиеся задачи).
>
> Этот план — система управления фильтрами бот-поиска через админ-панель с настраиваемыми погрешностями.

---

## Обзор

Менеджер пишет в Telegram-бота свободный текст ("хочу Hyundai Tucson 2020 пробег 140000 бензин").
Бот через AI конвертирует текст в фильтры, применяет **настраиваемые из админки** погрешности,
ищет машины и отправляет карточки с расширенной информацией (страховые случаи, ДТП, владельцы и т.д.).

**Что нового по сравнению с V2:**
- Погрешности настраиваются из админки (а не из `.env`)
- Для каждого поля можно выбрать тип погрешности: абсолютная или процентная
- Список полей для бот-фильтрации управляется из админки
- AI-промпт генерируется динамически из настроек
- Карточки бота показывают расширенную информацию

---

## Текущее состояние (что уже работает)

```
Manager → Telegram → WebhookController::handleTextSearch()
    → ChatSearchService::parseAndSearch(text)
        → Groq AI (llama-3.3-70b-versatile) → JSON фильтров
        → SearchQuery::fromArray(json)
        → SearchQuery::withTolerance()     ← читает из config/search_tolerance.php
    → ProviderAggregator::search(tolerantQuery)
        → KBChachaProvider::fetchRaw() → SELECT * FROM lots WHERE source='kbcha' AND is_active=1
        → AbstractProvider::applyFilters() → фильтрация в PHP-памяти по LotDTO
    → TelegramBot::sendLotCard() × 5 карточек
```

**Текущие проблемы:**
1. Погрешности захардкожены: `config/search_tolerance.php` → 4 поля (mileage ±30%, price ±20%, engine ±15%, year ±1)
2. AI-промпт статический: знает только 10 полей (make, model, year, price, mileage, engine, fuel, transmission, body, drive)
3. Карточки бота скудные: make, model, year, price, mileage, source, location, damage, link
4. Нет управления из админки — все настройки в коде

---

## Архитектура решения

```
┌─────────────────────────────────────────────────────────────┐
│                      ADMIN PANEL                             │
│                                                             │
│  /admin/bot-filters                                         │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Поле         │ Тип    │ Вкл │ Погрешность │ Карточка│   │
│  │──────────────│────────│─────│─────────────│─────────│   │
│  │ mileage      │ int    │ ✓   │ abs: 10000  │ ✓       │   │
│  │ price        │ int    │ ✓   │ pct: 15%    │ ✓       │   │
│  │ year         │ int    │ ✓   │ abs: 1      │ ✓       │   │
│  │ engine_vol   │ float  │ ✓   │ pct: 10%    │ ✓       │   │
│  │ fuel         │ enum   │ ✓   │ —           │ ✓       │   │
│  │ insurance_ct │ int    │ ✓   │ abs: 1      │ ✓       │   │
│  │ has_accident │ bool   │ ✓   │ —           │ ✓       │   │
│  │ ...          │        │     │             │         │   │
│  └─────────────────────────────────────────────────────┘   │
│                         ↓ Save                              │
│              bot_filter_settings table                       │
└─────────────────────────────────────────────────────────────┘
                          │
                          │ reads (cached 60s in Redis)
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                    BOT SEARCH FLOW                           │
│                                                             │
│  1. ChatSearchService::getSystemPrompt()                    │
│     ← динамически из BotFilterSetting::allEnabled()         │
│                                                             │
│  2. AI парсит текст → JSON                                  │
│                                                             │
│  3. SearchQuery::fromArray(json)                            │
│     ← поддерживает ВСЕ включённые поля                      │
│                                                             │
│  4. SearchQuery::withBotTolerance()                         │
│     ← читает tolerance_type + tolerance_value из БД         │
│                                                             │
│  5. ProviderAggregator::search()                            │
│     ← AbstractProvider::applyFilters() с новыми полями      │
│                                                             │
│  6. TelegramBot::sendLotCard()                              │
│     ← показывает поля с display_in_card=true                │
└─────────────────────────────────────────────────────────────┘
```

---

## Задача 1: Миграция + Модель + Сидер

### 1.1 Миграция `create_bot_filter_settings_table`

**Файл:** `laravel/database/migrations/2026_04_30_000001_create_bot_filter_settings_table.php`

```php
Schema::create('bot_filter_settings', function (Blueprint $table) {
    $table->id();
    $table->string('field_name', 50)->unique();        // из fields.json: 'mileage', 'price', 'year'
    $table->string('field_label', 100)->default('');    // человекочитаемое: 'Пробег', 'Цена'
    $table->string('dtype', 20)->default('string');     // 'int', 'float', 'enum', 'bool', 'string', 'date'
    $table->string('category', 30)->default('');        // 'identity', 'specs', 'condition', 'price', 'legal'
    $table->boolean('enabled')->default(false);         // включено для бот-фильтрации
    $table->string('tolerance_type', 20)->default('none'); // 'none', 'absolute', 'percentage'
    $table->decimal('tolerance_value', 12, 4)->nullable(); // 10000.0000 или 0.1500
    $table->boolean('display_in_card')->default(false); // показывать в карточке бота
    $table->json('enum_values')->nullable();            // ["gasoline","diesel",...] для enum-полей
    $table->text('description')->nullable();            // описание поля из fields.json
    $table->integer('sort_order')->default(0);          // порядок отображения в админке
    $table->timestamps();
});
```

### 1.2 Модель `BotFilterSetting`

**Файл:** `laravel/app/Models/BotFilterSetting.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BotFilterSetting extends Model
{
    protected $fillable = [
        'field_name', 'field_label', 'dtype', 'category',
        'enabled', 'tolerance_type', 'tolerance_value',
        'display_in_card', 'enum_values', 'description', 'sort_order',
    ];

    protected $casts = [
        'enabled'         => 'boolean',
        'display_in_card' => 'boolean',
        'tolerance_value' => 'float',
        'enum_values'     => 'array',
    ];

    private const CACHE_KEY = 'bot_filter_settings';
    private const CACHE_TTL = 60; // секунд

    /**
     * Все включённые поля (для AI-промпта и SearchQuery)
     * @return self[]
     */
    public static function allEnabled(): array
    {
        return Cache::remember(self::CACHE_KEY . ':enabled', self::CACHE_TTL, function () {
            return self::where('enabled', true)
                ->orderBy('sort_order')
                ->get()
                ->all();
        });
    }

    /**
     * Поля с настроенными погрешностями
     * @return array<string, array{type: string, value: float}>
     */
    public static function getTolerances(): array
    {
        return Cache::remember(self::CACHE_KEY . ':tolerances', self::CACHE_TTL, function () {
            return self::where('enabled', true)
                ->where('tolerance_type', '!=', 'none')
                ->whereNotNull('tolerance_value')
                ->get()
                ->mapWithKeys(fn ($s) => [
                    $s->field_name => [
                        'type'  => $s->tolerance_type,
                        'value' => $s->tolerance_value,
                    ],
                ])
                ->toArray();
        });
    }

    /**
     * Поля для отображения в карточке бота
     * @return string[]
     */
    public static function getCardFields(): array
    {
        return Cache::remember(self::CACHE_KEY . ':card_fields', self::CACHE_TTL, function () {
            return self::where('display_in_card', true)
                ->orderBy('sort_order')
                ->pluck('field_name')
                ->toArray();
        });
    }

    /**
     * Инвалидировать кэш (вызывать после сохранения в админке)
     */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY . ':enabled');
        Cache::forget(self::CACHE_KEY . ':tolerances');
        Cache::forget(self::CACHE_KEY . ':card_fields');
    }
}
```

### 1.3 Сидер `BotFilterSettingsSeeder`

**Файл:** `laravel/database/seeders/BotFilterSettingsSeeder.php`

Читает `storage/app/fields.json`, берёт все поля с `filterable: true`, создаёт записи в `bot_filter_settings`.

**Дефолтные значения при сидинге:**

| Поле | enabled | tolerance_type | tolerance_value | display_in_card |
|------|---------|----------------|-----------------|-----------------|
| make | true | none | — | true |
| model | true | none | — | true |
| year | true | absolute | 1 | true |
| price | true | percentage | 0.15 | true |
| mileage | true | absolute | 10000 | true |
| engine_volume | true | percentage | 0.10 | true |
| fuel | true | none | — | true |
| transmission | true | none | — | true |
| body_type | true | none | — | false |
| drive_type | true | none | — | false |
| color | false | none | — | false |
| has_accident | true | none | — | true |
| flood_history | true | none | — | true |
| total_loss_history | true | none | — | false |
| insurance_count | true | absolute | 1 | true |
| owners_count | true | absolute | 1 | true |
| repair_cost | false | percentage | 0.20 | false |
| retail_value | false | percentage | 0.15 | false |
| lien_status | true | none | — | false |
| seizure_status | true | none | — | false |
| sell_type | false | none | — | false |
| seat_count | false | none | — | false |
| registration_year_month | false | absolute | 6 | false |
| Остальные filterable | false | none | — | false |

**Логика сидера:**
- Если таблица пустая — заполняет из `fields.json` с дефолтами выше
- Если таблица НЕ пустая — добавляет только новые поля (не трогает существующие настройки)

---

## Задача 2: Админ-страница `/admin/bot-filters`

### 2.1 Роуты

**Файл:** `laravel/routes/web.php`

Добавить внутри `middleware('admin.auth')->prefix('admin')` группы:

```php
Route::get('/bot-filters', [AdminController::class, 'botFilters'])->name('admin.bot-filters');
Route::post('/bot-filters', [AdminController::class, 'botFiltersUpdate'])->name('admin.bot-filters.update');
Route::post('/bot-filters/reset', [AdminController::class, 'botFiltersReset'])->name('admin.bot-filters.reset');
```

### 2.2 Методы контроллера

**Файл:** `laravel/app/Http/Controllers/Admin/AdminController.php`

```php
public function botFilters()
{
    $settings = BotFilterSetting::orderBy('sort_order')->orderBy('field_name')->get();

    return view('admin.bot-filters', [
        'settings' => $settings,
        'categories' => $settings->groupBy('category'),
    ]);
}

public function botFiltersUpdate(Request $request)
{
    $fields = $request->input('fields', []);

    foreach ($fields as $id => $data) {
        BotFilterSetting::where('id', $id)->update([
            'enabled'         => !empty($data['enabled']),
            'tolerance_type'  => $data['tolerance_type'] ?? 'none',
            'tolerance_value' => $data['tolerance_value'] !== '' ? (float) $data['tolerance_value'] : null,
            'display_in_card' => !empty($data['display_in_card']),
        ]);
    }

    BotFilterSetting::flushCache();

    return redirect()->route('admin.bot-filters')
        ->with('success', 'Настройки бот-фильтров сохранены');
}

public function botFiltersReset()
{
    BotFilterSetting::truncate();
    Artisan::call('db:seed', ['--class' => 'BotFilterSettingsSeeder', '--force' => true]);
    BotFilterSetting::flushCache();

    return redirect()->route('admin.bot-filters')
        ->with('success', 'Настройки сброшены к дефолтным');
}
```

### 2.3 Blade-шаблон

**Файл:** `laravel/resources/views/admin/bot-filters.blade.php`

**Структура UI:**

```
┌──────────────────────────────────────────────────────────────────────────┐
│  Bot Filters Settings                                    [Сбросить] [✓]  │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ─── identity ──────────────────────────────────────────────────────     │
│                                                                          │
│  ☑ make         │ string │ Manufacturer           │ —        │ ☑ Card   │
│  ☑ model        │ string │ Model name             │ —        │ ☑ Card   │
│  ☑ year         │ int    │ Model year             │ [abs▼] [1]│ ☑ Card  │
│                                                                          │
│  ─── price ─────────────────────────────────────────────────────────     │
│                                                                          │
│  ☑ price        │ int    │ Displayed price (KRW)  │ [pct▼][15]│ ☑ Card  │
│  ☐ retail_value │ int    │ MSRP / new-car price   │ [pct▼][15]│ ☐ Card  │
│  ☐ repair_cost  │ int    │ Insurance repair cost  │ [pct▼][20]│ ☐ Card  │
│                                                                          │
│  ─── condition ─────────────────────────────────────────────────────     │
│                                                                          │
│  ☑ mileage      │ int    │ Odometer reading (km)  │ [abs▼][10000]│ ☑ Card│
│  ☑ has_accident │ bool   │ Accident history       │ —           │ ☑ Card │
│  ☑ insurance_ct │ int    │ Insurance claims       │ [abs▼] [1]  │ ☑ Card │
│  ☑ owners_count │ int    │ Previous owners        │ [abs▼] [1]  │ ☑ Card │
│                                                                          │
│  ─── specs ─────────────────────────────────────────────────────────     │
│                                                                          │
│  ☑ fuel         │ enum   │ Fuel type              │ —           │ ☑ Card │
│  ☑ transmission │ enum   │ Transmission           │ —           │ ☑ Card │
│  ☑ engine_vol   │ float  │ Engine displacement    │ [pct▼][10]  │ ☑ Card │
│  ☑ body_type    │ enum   │ Body style             │ —           │ ☐ Card │
│  ☑ drive_type   │ enum   │ Drivetrain             │ —           │ ☐ Card │
│  ☐ color        │ string │ Body color             │ —           │ ☐ Card │
│                                                                          │
│  ─── legal ─────────────────────────────────────────────────────────     │
│                                                                          │
│  ☑ lien_status  │ enum   │ Lien / loan status     │ —           │ ☐ Card │
│  ☑ seizure_stat │ enum   │ Seizure status         │ —           │ ☐ Card │
│                                                                          │
│                                          [Сохранить настройки]           │
└──────────────────────────────────────────────────────────────────────────┘
```

**Ключевые элементы:**
- Группировка полей по `category` с разделителями
- Checkbox "Включено" (`enabled`)
- Select "Тип погрешности" (`tolerance_type`) — показывается только для `dtype` = `int` / `float` / `date`
- Input "Значение" (`tolerance_value`) — показывается только если tolerance_type != 'none'
- Checkbox "В карточке" (`display_in_card`)
- Кнопка "Сбросить к дефолтам" с confirm-диалогом

**Стиль:** Tailwind (как в остальной админке), тёмная тема, аналогично `filters.blade.php`.

### 2.4 Sidebar навигация

**Файл:** `laravel/resources/views/admin/layout.blade.php`

Добавить ссылку в sidebar между "Filters" и "Filter Skip Log":

```html
<a href="{{ route('admin.bot-filters') }}" class="...">
  🤖 Bot Filters
</a>
```

---

## Задача 3: Расширить SearchQuery новыми полями

### 3.1 Новые поля в `SearchQuery`

**Файл:** `laravel/app/Services/SearchQuery.php`

Добавить свойства:

```php
// Condition fields
public int    $insuranceCountMin  = 0;
public int    $insuranceCountMax  = 0;
public int    $ownersCountMin     = 0;
public int    $ownersCountMax     = 0;
public ?bool  $hasAccident        = null;    // true = только с ДТП, false = без ДТП
public ?bool  $floodHistory       = null;
public ?bool  $totalLossHistory   = null;

// Price fields
public int    $repairCostMin      = 0;
public int    $repairCostMax      = 0;
public int    $retailValueMin     = 0;
public int    $retailValueMax     = 0;

// Legal/other
/** @var string[] */
public array  $lienStatuses       = [];      // ['clean']
/** @var string[] */
public array  $seizureStatuses    = [];      // ['clean']
/** @var string[] */
public array  $sellTypes          = [];      // ['sale', 'auction']
/** @var string[] */
public array  $colors             = [];

// Specs
public int    $seatCountMin       = 0;
public int    $seatCountMax       = 0;
```

### 3.2 Обновить `fromArray()`

**Файл:** `laravel/app/Services/SearchQuery.php`

Добавить парсинг новых полей в `fromArray()`:

```php
// Bool filters
$q->hasAccident      = isset($data['hasAccident'])      ? (bool) $data['hasAccident']      : null;
$q->floodHistory     = isset($data['floodHistory'])     ? (bool) $data['floodHistory']     : null;
$q->totalLossHistory = isset($data['totalLossHistory']) ? (bool) $data['totalLossHistory'] : null;

// Int range filters
$q->insuranceCountMin = (int) ($data['insuranceCountMin'] ?? 0);
$q->insuranceCountMax = (int) ($data['insuranceCountMax'] ?? 0);
$q->ownersCountMin    = (int) ($data['ownersCountMin']    ?? 0);
$q->ownersCountMax    = (int) ($data['ownersCountMax']    ?? 0);
$q->repairCostMin     = (int) ($data['repairCostMin']     ?? 0);
$q->repairCostMax     = (int) ($data['repairCostMax']     ?? 0);
$q->retailValueMin    = (int) ($data['retailValueMin']    ?? 0);
$q->retailValueMax    = (int) ($data['retailValueMax']    ?? 0);
$q->seatCountMin      = (int) ($data['seatCountMin']      ?? 0);
$q->seatCountMax      = (int) ($data['seatCountMax']      ?? 0);

// Array filters
foreach (['lienStatuses','seizureStatuses','sellTypes','colors'] as $key) {
    if (!empty($data[$key]) && is_array($data[$key])) {
        $q->$key = array_map('strval', $data[$key]);
    }
}
```

### 3.3 Обновить `applyFilters()` в AbstractProvider

**Файл:** `laravel/app/AuctionProviders/AbstractProvider.php`

Добавить внутрь замыкания в `applyFilters()`:

```php
// Bool filters
if ($query->hasAccident !== null && $lot->hasAccident !== $query->hasAccident) {
    return false;
}
if ($query->floodHistory !== null && $lot->floodHistory !== $query->floodHistory) {
    return false;
}
if ($query->totalLossHistory !== null && $lot->totalLossHistory !== $query->totalLossHistory) {
    return false;
}

// Insurance count
if ($query->insuranceCountMin > 0 && ($lot->insuranceCount === null || $lot->insuranceCount < $query->insuranceCountMin)) {
    return false;
}
if ($query->insuranceCountMax > 0 && ($lot->insuranceCount === null || $lot->insuranceCount > $query->insuranceCountMax)) {
    return false;
}

// Owners count
if ($query->ownersCountMin > 0 && ($lot->ownersCount === null || $lot->ownersCount < $query->ownersCountMin)) {
    return false;
}
if ($query->ownersCountMax > 0 && ($lot->ownersCount === null || $lot->ownersCount > $query->ownersCountMax)) {
    return false;
}

// Repair cost
if ($query->repairCostMin > 0 && ($lot->repairCost === null || $lot->repairCost < $query->repairCostMin)) {
    return false;
}
if ($query->repairCostMax > 0 && ($lot->repairCost === null || $lot->repairCost > $query->repairCostMax)) {
    return false;
}

// Lien status
if (!empty($query->lienStatuses) && ($lot->lienStatus === null || !in_array($lot->lienStatus, $query->lienStatuses, true))) {
    return false;
}

// Seizure status
if (!empty($query->seizureStatuses) && ($lot->seizureStatus === null || !in_array($lot->seizureStatus, $query->seizureStatuses, true))) {
    return false;
}

// Sell type
if (!empty($query->sellTypes) && ($lot->sellType === null || !in_array($lot->sellType, $query->sellTypes, true))) {
    return false;
}

// Color
if (!empty($query->colors)) {
    $lotColor = $lot->color ? strtolower(trim($lot->color)) : '';
    $match = false;
    foreach ($query->colors as $c) {
        if (str_contains($lotColor, strtolower(trim($c)))) { $match = true; break; }
    }
    if (!$match) return false;
}
```

### 3.4 Обновить `toSearchArray()` и `describeForChat()`

Добавить сериализацию новых полей в `toSearchArray()` и человекочитаемое описание в `describeForChat()`.

---

## Задача 4: Новая логика погрешностей из БД

### 4.1 Новый метод `withBotTolerance()`

**Файл:** `laravel/app/Services/SearchQuery.php`

Старый `withTolerance()` оставляем для обратной совместимости, но создаём новый:

```php
/**
 * Применить погрешности из настроек бот-фильтров (таблица bot_filter_settings)
 */
public function withBotTolerance(): self
{
    $tolerances = BotFilterSetting::getTolerances();

    if (empty($tolerances)) {
        return $this;
    }

    $clone = clone $this;

    // Маппинг: field_name → [minProperty, maxProperty] или [singleProperty]
    $rangeFields = [
        'mileage'       => ['mileageMin', 'mileageMax'],
        'price'         => ['priceMin', 'priceMax'],
        'engine_volume' => ['engineMin', 'engineMax'],
        'year'          => ['yearFrom', 'yearTo'],
        'insurance_count' => ['insuranceCountMin', 'insuranceCountMax'],
        'owners_count'  => ['ownersCountMin', 'ownersCountMax'],
        'repair_cost'   => ['repairCostMin', 'repairCostMax'],
        'retail_value'  => ['retailValueMin', 'retailValueMax'],
        'seat_count'    => ['seatCountMin', 'seatCountMax'],
    ];

    foreach ($rangeFields as $fieldName => [$minProp, $maxProp]) {
        if (!isset($tolerances[$fieldName])) {
            continue;
        }

        $t = $tolerances[$fieldName];

        if ($clone->$minProp > 0) {
            $clone->$minProp = $t['type'] === 'absolute'
                ? (int) ($clone->$minProp - $t['value'])
                : (int) round($clone->$minProp * (1 - $t['value']));
            $clone->$minProp = max(0, $clone->$minProp);
        }

        if ($clone->$maxProp > 0) {
            $clone->$maxProp = $t['type'] === 'absolute'
                ? (int) ($clone->$maxProp + $t['value'])
                : (int) round($clone->$maxProp * (1 + $t['value']));
        }
    }

    return $clone;
}
```

### 4.2 Обновить `ChatSearchService`

**Файл:** `laravel/app/Services/ChatSearchService.php`

Заменить вызов `withTolerance()` на `withBotTolerance()`:

```php
public function parseAndSearch(string $text): ?array
{
    $parsed = $this->parseQuery($text);
    if ($parsed === null) {
        return null;
    }

    $query = SearchQuery::fromArray($parsed);
    $query->limit = 50;

    $tolerantQuery = $query->withBotTolerance();  // ← ИЗМЕНЕНИЕ
    $description   = $query->describeForChat();
    $toleranceNote = $this->buildToleranceNote($query, $tolerantQuery);

    return [
        'query'         => $query,
        'tolerantQuery' => $tolerantQuery,
        'description'   => $description,
        'toleranceNote' => $toleranceNote,
    ];
}
```

### 4.3 Обновить `buildToleranceNote()`

Расширить для новых полей (insurance_count, owners_count и т.д.).

---

## Задача 5: Динамический AI-промпт

### 5.1 Переделать `getSystemPrompt()`

**Файл:** `laravel/app/Services/ChatSearchService.php`

Вместо статического промпта — генерация из `BotFilterSetting::allEnabled()`:

```php
private function getSystemPrompt(): string
{
    $enabledFields = BotFilterSetting::allEnabled();

    $filterDescriptions = [];
    foreach ($enabledFields as $setting) {
        $filterDescriptions[] = $this->buildFieldPromptLine($setting);
    }

    $filtersBlock = implode("\n", $filterDescriptions);

    return <<<PROMPT
Ты — парсер поисковых запросов для автомобилей. Пользователь пишет свободный текст, ты извлекаешь параметры поиска и возвращаешь JSON.

Доступные фильтры:
{$filtersBlock}

Правила:
1. Возвращай ТОЛЬКО JSON, без пояснений
2. Включай только те поля, которые явно упомянуты в тексте
3. "бензин"/"бенз"/"petrol" → fuelTypes: ["Gasoline"]
4. "дизель"/"diesel" → fuelTypes: ["Diesel"]
5. "электро"/"электрический"/"electric" → fuelTypes: ["Electric"]
6. "гибрид"/"hybrid" → fuelTypes: ["Hybrid"]
7. "автомат"/"АКПП"/"automatic" → transmissions: ["Automatic"]
8. "механика"/"МКПП"/"manual" → transmissions: ["Manual"]
9. "полный привод"/"AWD"/"4WD" → driveTypes: ["AWD"]
10. "передний привод"/"FWD" → driveTypes: ["FWD"]
11. "задний привод"/"RWD" → driveTypes: ["RWD"]
12. Числа после марки/модели без контекста — скорее всего объём двигателя (2.0, 2.5, 3.0) → engineMin и engineMax
13. "от X" → Min поле, "до X" → Max поле
14. Пробег определяй по контексту: "пробег от 10000" → mileageMin: 10000
15. Цену определяй по контексту: "до 15000$"/"до $15000" → priceMax: 15000
16. "без ДТП"/"без аварий" → hasAccident: false; "с ДТП" → hasAccident: true
17. "без залога"/"чистая" → lienStatuses: ["clean"]
18. "1 владелец"/"один хозяин" → ownersCountMax: 1
19. "без страховых"/"0 страховых" → insuranceCountMax: 0
20. "не затоплена"/"без утоплений" → floodHistory: false
21. Если текст не содержит параметров поиска авто — верни {"error": "not_a_search"}
PROMPT;
}
```

### 5.2 Метод `buildFieldPromptLine()`

```php
private function buildFieldPromptLine(BotFilterSetting $setting): string
{
    $name = $setting->field_name;
    $desc = $setting->description ?? $setting->field_label;

    return match ($setting->dtype) {
        'int' => $this->buildRangePromptLine($name, 'int', $desc),
        'float' => $this->buildRangePromptLine($name, 'float', $desc),
        'bool' => "- {$name} (bool) — {$desc}",
        'enum' => $this->buildEnumPromptLine($name, $setting->enum_values ?? [], $desc),
        'string' => "- {$name} (string) — {$desc}",
        default => "- {$name} — {$desc}",
    };
}

private function buildRangePromptLine(string $name, string $type, string $desc): string
{
    // Генерируем camelCase имена для min/max
    $camel = lcfirst(str_replace('_', '', ucwords($name, '_')));
    return "- {$camel}Min, {$camel}Max ({$type}) — {$desc}";
}

private function buildEnumPromptLine(string $name, array $values, string $desc): string
{
    $camel = lcfirst(str_replace('_', '', ucwords($name, '_')));
    // Для массивов-фильтров используем суффикс 's' или 'Types'
    $paramName = $this->getFilterParamName($name);
    $valuesStr = implode('", "', $values);
    return "- {$paramName} (string[]) — {$desc}. Допустимые: \"{$valuesStr}\"";
}
```

### 5.3 Кэширование промпта

Собранный промпт кэшировать в Redis:

```php
private function getSystemPrompt(): string
{
    return Cache::remember('bot_filter:system_prompt', 60, function () {
        return $this->buildSystemPrompt();
    });
}
```

Инвалидация — при сохранении в админке (`BotFilterSetting::flushCache()` уже чистит).

---

## Задача 6: Расширенные карточки бота

### 6.1 Новый формат карточки

**Файл:** `laravel/app/Services/TelegramBot.php`

Метод `sendLotCard()` — переделать для динамического состава полей:

```php
public function sendLotCard(int|string $chatId, array $lot, ?array $inlineKeyboard = null): array
{
    $text = $this->buildLotCardText($lot);

    $imageUrl = $lot['imageUrl'] ?? null;

    if ($imageUrl && $inlineKeyboard) {
        return $this->sendPhoto($chatId, $imageUrl, $text, [
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);
    }
    if ($imageUrl) {
        return $this->sendPhoto($chatId, $imageUrl, $text);
    }
    if ($inlineKeyboard) {
        return $this->sendMessageWithKeyboard($chatId, $text, $inlineKeyboard);
    }
    return $this->sendMessage($chatId, $text);
}

private function buildLotCardText(array $lot): string
{
    $cardFields = BotFilterSetting::getCardFields();

    $price = number_format((int) ($lot['price'] ?? 0), 0, '.', ',');
    $km    = number_format((int) ($lot['mileage'] ?? 0), 0, '.', ',');

    // Первая строка всегда: марка модель год · источник
    $lines = [
        sprintf("🚗 <b>%s %s %d</b> · %s",
            htmlspecialchars($lot['make'] ?? ''),
            htmlspecialchars($lot['model'] ?? ''),
            (int) ($lot['year'] ?? 0),
            htmlspecialchars($lot['sourceName'] ?? '')
        ),
    ];

    // Вторая строка: цена + пробег (всегда)
    $lines[] = sprintf("💰 <b>₩%s</b> · 🛣 %s km", $price, $km);

    // Спецификации (если включены в card)
    $specs = [];
    if (in_array('fuel', $cardFields) && !empty($lot['fuel'])) {
        $specs[] = '⛽ ' . $lot['fuel'];
    }
    if (in_array('transmission', $cardFields) && !empty($lot['transmission'])) {
        $specs[] = '⚙️ ' . $lot['transmission'];
    }
    if (in_array('engine_volume', $cardFields) && !empty($lot['engineVolume'])) {
        $specs[] = $lot['engineVolume'] . 'L';
    }
    if (in_array('drive_type', $cardFields) && !empty($lot['driveType'])) {
        $specs[] = $lot['driveType'];
    }
    if ($specs) {
        $lines[] = implode(' · ', $specs);
    }

    // Состояние (если включены в card)
    $condition = [];
    if (in_array('insurance_count', $cardFields)) {
        $ic = $lot['insuranceCount'] ?? null;
        if ($ic !== null) {
            $condition[] = "📋 {$ic} " . $this->ruInsurance($ic);
        }
    }
    if (in_array('has_accident', $cardFields)) {
        $ha = $lot['hasAccident'] ?? null;
        if ($ha === true) {
            $condition[] = '⚠️ Были ДТП';
        } elseif ($ha === false) {
            $condition[] = '✅ Без ДТП';
        }
    }
    if (in_array('owners_count', $cardFields)) {
        $oc = $lot['ownersCount'] ?? null;
        if ($oc !== null) {
            $condition[] = "👤 {$oc} " . $this->ruOwners($oc);
        }
    }
    if (in_array('flood_history', $cardFields)) {
        $fh = $lot['floodHistory'] ?? null;
        if ($fh === true) {
            $condition[] = '🌊 Затопление';
        }
    }
    if ($condition) {
        $lines[] = implode(' · ', $condition);
    }

    // Локация
    if (!empty($lot['location'])) {
        $lines[] = '📍 ' . htmlspecialchars($lot['location']);
    }

    // Ссылка
    $lotUrl = $lot['lotUrl'] ?? '#';
    if ($lotUrl !== '#') {
        $lines[] = sprintf('🔗 <a href="%s">Открыть лот</a>', htmlspecialchars($lotUrl));
    }

    return implode("\n", $lines);
}
```

### 6.2 Пример карточки (до и после)

**БЫЛО:**
```
🚗 Hyundai Tucson 2021 · KBChacha
💰 $15,000 · Lot #12345
📍 Seoul · 🗓 23 Apr
🛣 45,000 km
🔗 Открыть лот
```

**СТАЛО:**
```
🚗 Hyundai Tucson 2021 · KBChacha
💰 ₩15,000,000 · 🛣 45,000 km
⛽ Gasoline · ⚙️ Automatic · 2.0L
📋 2 страховых · ✅ Без ДТП · 👤 1 владелец
📍 Seoul, Korea
🔗 Открыть лот
```

### 6.3 Вспомогательные методы склонения

```php
private function ruInsurance(int $n): string
{
    $mod10 = $n % 10; $mod100 = $n % 100;
    if ($mod10 === 1 && $mod100 !== 11) return 'страховой';
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) return 'страховых';
    return 'страховых';
}

private function ruOwners(int $n): string
{
    $mod10 = $n % 10; $mod100 = $n % 100;
    if ($mod10 === 1 && $mod100 !== 11) return 'владелец';
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) return 'владельца';
    return 'владельцев';
}
```

---

## Задача 7: Обновить fallbackParse()

### 7.1 Новые regex-паттерны

**Файл:** `laravel/app/Services/ChatSearchService.php`

Добавить в `fallbackParse()`:

```php
// Страховые случаи
if (preg_match('/(?:страхов|insurance).*?(?:до|max|<)\s*(\d+)/u', $text, $m)) {
    $result['insuranceCountMax'] = (int) $m[1];
}
if (preg_match('/(?:без\s*страхов|0\s*страхов)/u', $text)) {
    $result['insuranceCountMax'] = 0;
}

// Владельцы
if (preg_match('/(\d+)\s*(?:владел|хозя|owner)/u', $text, $m)) {
    $result['ownersCountMax'] = (int) $m[1];
}

// ДТП
if (preg_match('/без\s*(?:дтп|авар|accident)/ui', $text)) {
    $result['hasAccident'] = false;
}
if (preg_match('/(?:с\s*дтп|были\s*авар|has.*accident)/ui', $text)) {
    $result['hasAccident'] = true;
}

// Затопление
if (preg_match('/без\s*(?:затопл|утопл|flood)/ui', $text)) {
    $result['floodHistory'] = false;
}

// Залог
if (preg_match('/без\s*(?:залог|обременен|lien)/ui', $text)) {
    $result['lienStatuses'] = ['clean'];
}

// Цвет
$colorMap = [
    'белый' => 'White', 'белая' => 'White', 'white' => 'White',
    'черный' => 'Black', 'черная' => 'Black', 'black' => 'Black',
    'серый' => 'Gray', 'серая' => 'Gray', 'серебр' => 'Silver',
    'красный' => 'Red', 'красная' => 'Red', 'red' => 'Red',
    'синий' => 'Blue', 'синяя' => 'Blue', 'blue' => 'Blue',
];
foreach ($colorMap as $keyword => $value) {
    if (mb_stripos($text, $keyword) !== false) {
        $result['colors'] = [$value];
        break;
    }
}
```

---

## Задача 8: Маппинг имён полей (AI JSON → SearchQuery)

### Проблема

AI возвращает JSON с camelCase ключами (`insuranceCountMax`), а `fields.json` использует snake_case (`insurance_count`).
Нужен единый маппинг.

### Решение

**Файл:** `laravel/app/Services/SearchQuery.php` — метод `fromArray()` уже принимает camelCase.
Просто убедиться, что все новые поля правильно маппятся:

| AI JSON ключ | SearchQuery свойство | DB column | fields.json |
|---|---|---|---|
| `insuranceCountMin` | `$insuranceCountMin` | `insurance_count` | `insurance_count` |
| `insuranceCountMax` | `$insuranceCountMax` | `insurance_count` | `insurance_count` |
| `ownersCountMin` | `$ownersCountMin` | `owners_count` | `owners_count` |
| `ownersCountMax` | `$ownersCountMax` | `owners_count` | `owners_count` |
| `hasAccident` | `$hasAccident` | `has_accident` | `has_accident` |
| `floodHistory` | `$floodHistory` | `flood_history` | `flood_history` |
| `totalLossHistory` | `$totalLossHistory` | `total_loss_history` | `total_loss_history` |
| `repairCostMin` | `$repairCostMin` | `repair_cost` | `repair_cost` |
| `repairCostMax` | `$repairCostMax` | `repair_cost` | `repair_cost` |
| `lienStatuses` | `$lienStatuses` | `lien_status` | `lien_status` |
| `seizureStatuses` | `$seizureStatuses` | `seizure_status` | `seizure_status` |
| `sellTypes` | `$sellTypes` | `sell_type` | `sell_type` |
| `colors` | `$colors` | `color` | `color` |

---

## Порядок реализации

```
Этап 1 (параллельно):
├── Задача 1: Миграция + Модель + Сидер
├── Задача 3: Расширить SearchQuery + applyFilters
└── Задача 6: Новый формат карточки (sendLotCard)

Этап 2 (зависит от Задачи 1):
├── Задача 2: Админ-страница /admin/bot-filters
└── Задача 4: withBotTolerance() из БД

Этап 3 (зависит от Задач 1+3):
├── Задача 5: Динамический AI-промпт
└── Задача 7: Обновить fallbackParse()

Этап 4:
└── Задача 8: Тестирование полного flow
```

**Оценка:** 3-4 дня разработки.

---

## Файлы, которые будут изменены

| Файл | Действие |
|------|----------|
| `laravel/database/migrations/2026_04_30_..._create_bot_filter_settings_table.php` | NEW |
| `laravel/app/Models/BotFilterSetting.php` | NEW |
| `laravel/database/seeders/BotFilterSettingsSeeder.php` | NEW |
| `laravel/resources/views/admin/bot-filters.blade.php` | NEW |
| `laravel/routes/web.php` | EDIT — добавить роуты bot-filters |
| `laravel/app/Http/Controllers/Admin/AdminController.php` | EDIT — добавить 3 метода |
| `laravel/resources/views/admin/layout.blade.php` | EDIT — sidebar ссылка |
| `laravel/app/Services/SearchQuery.php` | EDIT — новые поля + withBotTolerance() |
| `laravel/app/AuctionProviders/AbstractProvider.php` | EDIT — расширить applyFilters() |
| `laravel/app/Services/ChatSearchService.php` | EDIT — динамический промпт + fallback |
| `laravel/app/Services/TelegramBot.php` | EDIT — новый sendLotCard() |

---

## Задача 9: AI-батч маппинг model_en (Korean → English)

### Проблема

В базе ~300-500 уникальных моделей с `model_en = NULL`. Текущий словарь `_KR_TO_EN` покрывает только
корейские бренды. Не покрыты: BMW, Mercedes-Benz, Renault Korea, Land Rover, Lexus, Mini, Lincoln, Dodge и др.

Готовых библиотек для этого маппинга не существует. Используем AI-батч: отправляем все уникальные
`(make, model)` пары в LLM, получаем маппинг, сохраняем в словарь и обновляем БД.

### Ограничение: данные на сервере (Railway)

База данных доступна только на Railway. Локально подключиться к ней нельзя (или неудобно).
Поэтому весь процесс выполняется через **artisan-команду на сервере**, а результат сохраняется
в файл, доступный через HTTP (для скачивания).

### План реализации

#### 9.1 Artisan-команда `model:generate-en-mapping`

**Файл:** `laravel/app/Console/Commands/GenerateModelEnMapping.php`

Логика:
1. `SELECT DISTINCT make, model FROM lots WHERE model_en IS NULL ORDER BY make, model`
2. Группировать по make (для контекста)
3. Отправить батчами по 50-100 моделей в AI (Groq/LLaMA — уже подключён в `config/ai.php`)
4. System prompt:
   ```
   Ты — эксперт по автомобилям. Я дам тебе список моделей автомобилей в корейском формате.
   Для каждой модели определи каноническое английское название.
   
   Правила:
   - BMW "5시리즈 (G30) 520d xDrive" → "5 Series" (серия, без варианта)
   - Mercedes "E-클래스 W213 E300" → "E-Class" (класс, без варианта)
   - Renault "SM5 노바 RE" → "SM5" (извлечь латинский код)
   - Lexus "LS460 슈프림" → "LS460" (извлечь латинский код)
   - Mini "쿠퍼 D 클럽맨" → "Cooper Clubman"
   - Land Rover "레인지로버 스포츠" → "Range Rover Sport"
   - Если не можешь определить — верни null
   
   Верни JSON: {"results": [{"make": "...", "model_kr": "...", "model_en": "..."}]}
   ```
5. Собрать все результаты в один JSON
6. Сохранить результат в `storage/app/model_en_mapping.json`
7. Сделать файл доступным по HTTP через `/admin/model-en-mapping.json`

#### 9.2 Artisan-команда `model:apply-en-mapping`

**Файл:** тот же `GenerateModelEnMapping.php` с опцией `--apply`

Или отдельная команда. Логика:
1. Прочитать `storage/app/model_en_mapping.json`
2. Для каждой пары `(make, model_kr) → model_en`:
   ```sql
   UPDATE lots SET model_en = '{model_en}' WHERE make = '{make}' AND model = '{model_kr}' AND model_en IS NULL
   ```
3. Вывести статистику: сколько обновлено, сколько осталось NULL

#### 9.3 Роут для скачивания результата

**Файл:** `laravel/routes/web.php`

```php
Route::get('/admin/model-en-mapping.json', function () {
    $path = storage_path('app/model_en_mapping.json');
    if (!file_exists($path)) {
        abort(404, 'Mapping file not found. Run: php artisan model:generate-en-mapping');
    }
    return response()->file($path, ['Content-Type' => 'application/json']);
})->middleware('admin.auth');
```

Это позволяет:
- Запустить команду на Railway: `php artisan model:generate-en-mapping`
- Открыть в браузере: `https://your-app.railway.app/admin/model-en-mapping.json`
- Скачать файл, проверить маппинг вручную
- Применить: `php artisan model:apply-en-mapping`

#### 9.4 Обновить Python-словарь `_KR_TO_EN`

После проверки JSON-файла — добавить новые маппинги в `parser/parsers/_shared/korean_model_names.py`.
Это нужно чтобы новые лоты при парсинге сразу получали `model_en`.

Можно автоматизировать: artisan-команда `model:export-to-python` генерирует Python-dict из JSON.

#### 9.5 Исправить поиск — искать по model_en ИЛИ model

**Файл:** `laravel/app/AuctionProviders/AbstractDbProvider.php`

```php
// Было:
if ($query->model) $builder->whereRaw('model_en LIKE ?', ['%' . $query->model . '%']);

// Стало:
if ($query->model) {
    $builder->where(function ($q) use ($query) {
        $q->where('model_en', 'LIKE', '%' . $query->model . '%')
          ->orWhere('model', 'LIKE', '%' . $query->model . '%');
    });
}
```

Это покрывает случай когда менеджер пишет "BMW 520d" — `model_en` = "5 Series" не совпадёт,
но `model` = "5시리즈 (G30) 520d xDrive" — содержит "520d" и совпадёт.

### Workflow (пошагово)

```
1. Задеплоить код на Railway (команда + роут)
2. Запустить: railway run php artisan model:generate-en-mapping
3. Подождать ~30-60 сек (AI батч)
4. Открыть: https://app.railway.app/admin/model-en-mapping.json
5. Проверить глазами (или скачать)
6. Если ОК: railway run php artisan model:apply-en-mapping
7. Скопировать маппинги в korean_model_names.py
```

### Стоимость

~300-500 уникальных моделей × ~50 tokens/entry = ~25K tokens.
На Groq (LLaMA 3.3 70B) — бесплатно (rate limit). На OpenAI — ~$0.05.

---

## Вопросы / решения

1. **Старый `config/search_tolerance.php`** — оставить как fallback или удалить?
   → Рекомендация: оставить, `withBotTolerance()` работает поверх, старый `withTolerance()` не трогаем.

2. **Кэш** — Redis или file?
   → Redis (уже подключён в проекте через predis/predis).

3. **Валюта в карточках** — KRW или USD?
   → KRW (₩), т.к. в DB цены в KRW. Позже можно добавить конвертацию.
