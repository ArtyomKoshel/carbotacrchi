<?php

namespace App\Livewire\Platform;

use App\Filament\Pages\Platform;
use App\Filament\Resources\Lots\LotFormat;
use App\Models\ParseFilter;
use App\Services\ParseFilterRules;
use App\Services\ParseRuns;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * «Правила отбора»: parse_filters that apply to this platform (its own and
 * the ones without a source). Order = priority; the parser reloads rules
 * every 60 s.
 */
class Rules extends PlatformComponent implements HasTable
{
    use InteractsWithTable;

    protected const PAGE_KEY = 'filters';

    private const SINGLE_OPS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte'];

    private const TEXT_OPS = ['eq', 'ne', 'contains', 'not_contains', 'regex'];

    public function render(): View
    {
        return view('livewire.platform.table', [
            'intro' => 'Правила проверяются сверху вниз. Правила без группы срабатывают по отдельности (ИЛИ); '
                . 'правила с одной группой срабатывают, только когда совпали все (И). «Всегда пропускать» останавливает проверку. '
                . 'Парсер перечитывает правила раз в минуту.',
        ]);
    }

    private function rules(): ParseFilterRules
    {
        return app(ParseFilterRules::class);
    }

    /** @return array<string, array{lots: int, hits: int, last: ?string}> */
    private function hits(): array
    {
        return once(fn () => ParseFilterRules::hits($this->source));
    }

    /** @return array<string, int> rule name => lots the last finished full run filtered */
    private function lastRunHits(): array
    {
        return once(fn () => (array) (app(ParseRuns::class)->lastUnfilteredRun($this->source)?->result['filter_rules'] ?? []));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ParseFilter::query()->where(fn (Builder $q) => $q->whereNull('source')->orWhere('source', $this->source)))
            ->defaultSort(fn (Builder $query) => $query->orderBy('priority')->orderBy('id'))
            ->reorderable('priority')
            ->reorderRecordsTriggerAction(fn (Action $action, bool $isReordering) => $action->label($isReordering ? 'Готово' : 'Изменить порядок'))
            ->paginated(false)
            ->columns([
                TextColumn::make('sentence')->label('Правило')
                    ->state(fn (ParseFilter $record) => $this->rules()->sentence($record))
                    ->description(fn (ParseFilter $record) => trim($record->name . ($record->description ? ' · ' . $record->description : '')))
                    ->weight('medium')
                    ->wrap(),
                TextColumn::make('phase')->label('Когда')
                    ->formatStateUsing(fn (?string $state) => ParseFilterRules::PHASES[$state] ?? $state),
                TextColumn::make('rule_group_id')->label('Группа И')
                    ->badge()->color('gray')
                    ->placeholder('—')
                    ->tooltip(fn (ParseFilter $record) => $record->rule_group_id ? 'Срабатывает, только если совпали все правила группы' : null),
                TextColumn::make('source')->label('Площадка')
                    ->formatStateUsing(fn (?string $state) => $state ?: 'все')
                    ->placeholder('все')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hits')->label('Сработало')
                    ->state(fn (ParseFilter $record) => $this->hitsLine($record))
                    ->description(fn (ParseFilter $record) => isset($this->lastRunHits()[$record->name])
                        ? 'за последний прогон: ' . LotFormat::number((int) $this->lastRunHits()[$record->name])
                        : null)
                    ->url(fn (ParseFilter $record) => isset($this->hits()[$record->name])
                        ? Platform::getUrl(['source' => $this->source, 'tab' => 'skips', 'rule' => $record->name])
                        : null),
                TextColumn::make('enabled')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'включено' : 'выключено')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Новое правило')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalHeading('Новое правило отбора')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->schema(fn () => $this->ruleForm())
                    ->mutateDataUsing(fn (array $data) => $this->toRow($data))
                    ->using(fn (array $data) => ParseFilter::create($data))
                    ->successNotificationTitle('Правило создано, парсер подхватит его в течение минуты'),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (ParseFilter $record) => $record->enabled ? 'Выключить' : 'Включить')
                    ->icon(fn (ParseFilter $record) => $record->enabled ? Heroicon::OutlinedPause : Heroicon::OutlinedPlay)
                    ->iconButton()
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (ParseFilter $record) => ($record->enabled ? 'Выключить' : 'Включить') . ' правило?')
                    ->modalDescription(fn (ParseFilter $record) => $this->rules()->sentence($record) . '. '
                        . ($record->enabled
                            ? 'Со следующей минуты парсер перестанет его применять: такие лоты начнут попадать в базу.'
                            : $this->previewSentence($record->field, $record->operator, $record->decodedValue())))
                    ->action(function (ParseFilter $record) {
                        $record->update(['enabled' => !$record->enabled]);
                        Notification::make()->title($record->enabled ? 'Правило включено' : 'Правило выключено')->success()->send();
                    }),
                EditAction::make()
                    ->iconButton()
                    ->modalHeading('Правило отбора')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->schema(fn () => $this->ruleForm())
                    ->fillForm(fn (ParseFilter $record) => $this->toForm($record))
                    ->mutateDataUsing(fn (array $data, ParseFilter $record) => $this->toRow($data, $record)),
                DeleteAction::make()
                    ->iconButton()
                    ->color('gray')
                    ->modalHeading('Удалить правило?')
                    ->modalDescription(fn (ParseFilter $record) => $this->rules()->sentence($record)
                        . '. Парсер перестанет его применять в течение минуты. Его строки в логе пропусков останутся. Если нужно временно, лучше выключите.'),
            ])
            ->emptyStateHeading('Правил нет: парсер сохраняет все лоты');
    }

    private function hitsLine(ParseFilter $record): string
    {
        $h = $this->hits()[$record->name] ?? null;
        if (!$h) {
            return in_array($record->action, ['skip', 'mark_inactive'], true) ? 'нет в логе' : '—';
        }

        return LotFormat::number($h['lots']) . ' ' . \App\Support\JobFormat::plural($h['lots'], 'лот', 'лота', 'лотов')
            . ($h['last'] ? ' · ' . \Carbon\Carbon::parse($h['last'])->diffForHumans() : '');
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    private function ruleForm(): array
    {
        $r = $this->rules();
        $dtype = fn (Get $get) => $r->dtype($get('field'));
        $op = fn (Get $get) => (string) $get('operator');
        $isEnum = fn (Get $get) => $r->enumOptions($get('field')) !== null;
        $isNum = fn (Get $get) => in_array($dtype($get), ['int', 'float'], true);

        return [
            Grid::make(2)->schema([
                Radio::make('phase')->label('Когда проверять')
                    ->options(ParseFilterRules::PHASES)
                    ->descriptions(['pre' => 'по данным из поиска, до запроса истории', 'post' => 'после истории и осмотра (поля ДТП, страховые, осмотр)'])
                    ->default('pre')->required(),
                Radio::make('action')->label('Что делать')
                    ->options(ParseFilterRules::ACTIONS)
                    ->descriptions(ParseFilterRules::ACTION_HINTS)
                    ->default('skip')->required()->live(),
            ]),
            Section::make('Условие')->compact()->schema([
                Grid::make(2)->schema([
                    Select::make('field')->label('Поле')
                        ->options($r->fieldOptions())
                        ->searchable()->required()->live()
                        ->afterStateUpdated(fn (Set $set) => $set('operator', null)),
                    Select::make('operator')->label('Условие')
                        ->options(fn (Get $get) => $r->operatorOptions($get('field')))
                        ->required()->live()
                        ->disabled(fn (Get $get) => blank($get('field'))),
                ]),
                Select::make('value_enum')->label('Значение')
                    ->options(fn (Get $get) => $r->enumOptions($get('field')) ?? [])
                    ->visible(fn (Get $get) => $isEnum($get) && in_array($op($get), ['eq', 'ne'], true))
                    ->required()->live(),
                Select::make('value_enum_list')->label('Значения')->multiple()
                    ->options(fn (Get $get) => $r->enumOptions($get('field')) ?? [])
                    ->visible(fn (Get $get) => $isEnum($get) && ParseFilterRules::isList($op($get)))
                    ->required()->live(),
                Select::make('value_bool')->label('Значение')
                    ->options(['1' => 'да', '0' => 'нет'])
                    ->visible(fn (Get $get) => $dtype($get) === 'bool' && in_array($op($get), ['eq', 'ne'], true))
                    ->required()->live(),
                TextInput::make('value_number')->label('Значение')->numeric()
                    ->suffix(fn (Get $get) => self::unit($get('field')))
                    ->visible(fn (Get $get) => $isNum($get) && in_array($op($get), self::SINGLE_OPS, true))
                    ->required()->live(onBlur: true),
                Grid::make(2)
                    ->visible(fn (Get $get) => $op($get) === 'between')
                    ->schema([
                        TextInput::make('value_from')->label('От')->numeric()->required()->live(onBlur: true)->suffix(fn (Get $get) => self::unit($get('field'))),
                        TextInput::make('value_to')->label('До')->numeric()->required()->live(onBlur: true)->suffix(fn (Get $get) => self::unit($get('field'))),
                    ]),
                TagsInput::make('value_list')->label('Значения')
                    ->placeholder('Введите и нажмите Enter')
                    ->visible(fn (Get $get) => !$isEnum($get) && ParseFilterRules::isList($op($get)))
                    ->required()->live(),
                TextInput::make('value_text')->label(fn (Get $get) => $op($get) === 'regex' ? 'Шаблон (регулярное выражение)' : 'Значение')
                    ->visible(fn (Get $get) => !$isEnum($get) && !$isNum($get) && $dtype($get) !== 'bool' && in_array($op($get), self::TEXT_OPS, true))
                    ->required()->live(onBlur: true),
                Text::make(fn (Get $get) => $this->formPreview($get))
                    ->visible(fn (Get $get) => filled($get('field')) && filled($get('operator'))),
            ]),
            Grid::make(2)->schema([
                TextInput::make('rule_group_id')->label('Группа И')
                    ->datalist(fn () => ParseFilter::whereNotNull('rule_group_id')->distinct()->pluck('rule_group_id')->all())
                    ->regex('/^[a-z0-9_]+$/i')
                    ->validationMessages(['regex' => 'Только латиница, цифры и «_».'])
                    ->helperText('Пусто — правило работает само по себе. Одинаковое имя у нескольких правил — они срабатывают только вместе.'),
                TextInput::make('description')->label('Комментарий')->maxLength(255),
            ]),
            Select::make('scope')->label('Площадки')
                ->options(['all' => 'Все площадки', 'this' => 'Только эта площадка'])
                ->default('this')->required(),
        ];
    }

    private function formPreview(Get $get): HtmlString
    {
        $field = (string) $get('field');
        $operator = (string) $get('operator');
        $typed = $this->valueFromForm($field, $operator, fn (string $k) => $get($k));
        if ($typed === null) {
            return new HtmlString('<span class="text-gray-500">Укажите значение, чтобы увидеть, сколько лотов попадёт под правило.</span>');
        }
        [$value] = $typed;
        $sentence = (ParseFilterRules::ACTIONS[$get('action')] ?? '') . ', если ' . $this->rules()->condition($field, $operator, $value);

        return new HtmlString('<strong>' . e($sentence) . '.</strong> ' . e($this->previewSentence($field, $operator, $value)));
    }

    private function previewSentence(string $field, string $operator, mixed $value): string
    {
        $n = $this->rules()->previewCount($field, $operator, $value, $this->source);
        if ($n === null) {
            return 'Этого поля нет в таблице лотов, заранее посчитать нельзя.';
        }

        return 'Сейчас под условие попадает ' . LotFormat::number($n) . ' ' . \App\Support\JobFormat::plural($n, 'активный лот', 'активных лота', 'активных лотов') . '.';
    }

    /**
     * Typed value from the form as [$value], or null while it is incomplete.
     * (Wrapped: false and null are real rule values, «затопление = нет».)
     *
     * @param  \Closure(string): mixed  $get
     */
    private function valueFromForm(string $field, string $operator, \Closure $get): mixed
    {
        $r = $this->rules();
        if ($field === '' || $operator === '') {
            return null;
        }
        if (!ParseFilterRules::needsValue($operator)) {
            return [null];
        }
        $isEnum = $r->enumOptions($field) !== null;
        $dtype = $r->dtype($field);
        $raw = match (true) {
            $operator === 'between'                    => ['from' => $get('value_from'), 'to' => $get('value_to')],
            $isEnum && ParseFilterRules::isList($operator) => $get('value_enum_list'),
            ParseFilterRules::isList($operator)        => $get('value_list'),
            $isEnum                                    => $get('value_enum'),
            $dtype === 'bool'                          => $get('value_bool'),
            in_array($dtype, ['int', 'float'], true)   => $get('value_number'),
            default                                    => $get('value_text'),
        };
        if ($raw === null || $raw === '' || $raw === [] || (is_array($raw) && in_array(null, array_map(fn ($v) => $v === '' ? null : $v, $raw), true) && $operator === 'between')) {
            return null;
        }

        return [$r->castValue($field, $operator, $raw)];
    }

    /** Form state → parse_filters row. */
    private function toRow(array $data, ?ParseFilter $existing = null): array
    {
        [$value] = $this->valueFromForm($data['field'], $data['operator'], fn (string $k) => $data[$k] ?? null) ?? [null];

        $row = [
            'source'        => ($data['scope'] ?? 'this') === 'all' ? null : $this->source,
            'field'         => $data['field'],
            'operator'      => $data['operator'],
            'value'         => ParseFilterRules::encodeValue($data['operator'], $value),
            'action'        => $data['action'],
            'phase'         => $data['phase'],
            'rule_group_id' => filled($data['rule_group_id'] ?? null) ? $data['rule_group_id'] : null,
            'description'   => $data['description'] ?? null,
        ];
        if (!$existing) {
            // A new rule goes last; its name is generated (skip-log history keys on the name, so it never changes)
            $row['name'] = ParseFilterRules::autoName($data['field'], $data['operator'], $value);
            $row['priority'] = (int) ParseFilter::max('priority') + 10;
            $row['enabled'] = true;
        }

        return $row;
    }

    /** parse_filters row → form state. */
    private function toForm(ParseFilter $rule): array
    {
        $value = $rule->decodedValue();
        $isEnum = $this->rules()->enumOptions($rule->field) !== null;
        $dtype = $this->rules()->dtype($rule->field);

        $form = [
            'phase' => $rule->phase, 'action' => $rule->action, 'field' => $rule->field, 'operator' => $rule->operator,
            'rule_group_id' => $rule->rule_group_id, 'description' => $rule->description,
            'scope' => $rule->source ? 'this' : 'all',
        ];
        match (true) {
            $rule->operator === 'between'                       => [$form['value_from'], $form['value_to']] = array_pad((array) $value, 2, null),
            $isEnum && ParseFilterRules::isList($rule->operator) => $form['value_enum_list'] = (array) $value,
            ParseFilterRules::isList($rule->operator)           => $form['value_list'] = array_map('strval', (array) $value),
            $isEnum                                             => $form['value_enum'] = $value,
            $dtype === 'bool'                                   => $form['value_bool'] = $value ? '1' : '0',
            in_array($dtype, ['int', 'float'], true)            => $form['value_number'] = $value,
            default                                             => $form['value_text'] = is_scalar($value) ? (string) $value : null,
        };

        return $form;
    }

    private static function unit(?string $field): ?string
    {
        return match ($field) {
            'price', 'retail_value', 'repair_cost', 'accident_max_cost', 'total_accident_cost' => '₩',
            'mileage', 'inspection_mileage' => 'км',
            'engine_volume' => 'л',
            default => null,
        };
    }
}
