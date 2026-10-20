<?php

namespace App\Livewire\Platform;

use App\Filament\Resources\Lots\LotFormat;
use App\Models\FilterSkipLog;
use App\Models\ParseFilter;
use App\Services\ParseFilterRules;
use App\Support\JobFormat;
use App\Support\Platforms;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * «Лог пропусков»: lots the parser's rules skipped or saved inactive, one row
 * per lot and rule (filter_skip_log). Rows idle for 30 days are pruned.
 */
class SkipLog extends PlatformComponent implements HasTable
{
    use InteractsWithTable;

    protected const PAGE_KEY = 'filter-skip-log';

    /** Rule name from the «Правила отбора» link */
    #[Url]
    public ?string $rule = null;

    public function render(): View
    {
        return view('livewire.platform.table', [
            'intro' => 'Лоты, которые не прошли правила отбора. Одна строка — один лот и одно правило; «раз» — сколько прогонов подряд правило его отсеивало. '
                . 'Строки, которые не обновлялись ' . FilterSkipLog::RETENTION_DAYS . ' дней, удаляются автоматически.',
        ]);
    }

    public function table(Table $table): Table
    {
        $rules = app(ParseFilterRules::class);
        $byName = ParseFilter::all()->keyBy('name');

        return $table
            ->query(FilterSkipLog::query()->where('source', $this->source))
            ->defaultSort('last_skipped_at', 'desc')
            ->columns([
                TextColumn::make('source_id')->label('Лот')
                    ->url(fn (FilterSkipLog $record) => Platforms::lotUrl($record->source, $record->source_id, $record->getAttributes()['lot_url'] ?? null), shouldOpenInNewTab: true)
                    ->color('primary')
                    ->searchable(),
                TextColumn::make('rule_name')->label('Правило')
                    ->description(fn (FilterSkipLog $record) => isset($byName[$record->rule_name]) ? $rules->sentence($byName[$record->rule_name]) : 'правило удалено'),
                TextColumn::make('field_value')->label('Значение')
                    ->state(fn (FilterSkipLog $record) => $record->field_name
                        ? \App\Support\AdminUiLabels::field($record->field_name) . ': ' . $rules->formatValue($record->field_name, self::decode($record->field_value))
                        : '—'),
                TextColumn::make('action')->label('Что сделано')
                    ->formatStateUsing(fn (?string $state) => ParseFilterRules::ACTIONS[$state] ?? $state),
                TextColumn::make('skipped_at')->label('Впервые')->since()->dateTimeTooltip('d.m.Y H:i')->sortable(),
                TextColumn::make('last_skipped_at')->label('Последний раз')->since()->dateTimeTooltip('d.m.Y H:i')->sortable(),
                TextColumn::make('skip_count')->label('Раз')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => LotFormat::number((int) $state)),
            ])
            ->filters([
                SelectFilter::make('rule_name')->label('Правило')
                    ->default($this->rule)
                    ->options(fn () => FilterSkipLog::where('source', $this->source)->distinct()->orderBy('rule_name')->pluck('rule_name', 'rule_name')->all()),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('Отсеивался с'),
                        DatePicker::make('to')->label('по'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query->byDateRange(
                        $data['from'] ?? null,
                        filled($data['to'] ?? null) ? $data['to'] . ' 23:59:59' : null,
                    ))
                    ->indicateUsing(fn (array $data) => array_values(array_filter([
                        filled($data['from'] ?? null) ? Indicator::make('с ' . \Carbon\Carbon::parse($data['from'])->format('d.m.Y'))->removeField('from') : null,
                        filled($data['to'] ?? null) ? Indicator::make('по ' . \Carbon\Carbon::parse($data['to'])->format('d.m.Y'))->removeField('to') : null,
                    ]))),
            ])
            ->headerActions([
                Action::make('cleanup')
                    ->label('Очистить старые')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('gray')
                    ->modalHeading('Очистить лог пропусков')
                    ->schema([
                        TextInput::make('days')->label('Удалить строки, которые не обновлялись, дней')
                            ->numeric()->minValue(1)->default(FilterSkipLog::RETENTION_DAYS)->required()->live(onBlur: true),
                        Text::make(fn (Get $get) => ($d = (int) $get('days')) >= 1
                            ? 'Будет удалено ' . LotFormat::number(self::staleQuery($d)->count()) . ' ' . JobFormat::plural(self::staleQuery($d)->count(), 'строка', 'строки', 'строк')
                                . ' по всем площадкам. Сами лоты и правила не затрагиваются.'
                            : null),
                    ])
                    ->modalSubmitActionLabel('Удалить')
                    ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
                    ->action(function (array $data) {
                        $deleted = self::staleQuery((int) $data['days'])->delete();
                        Notification::make()->title('Удалено строк: ' . LotFormat::number($deleted))->success()->send();
                    }),
            ])
            ->emptyStateHeading('Правила ничего не отсеивали');
    }

    /** Same rule as the old cleanup and model:prune: not skipped for N days. */
    private static function staleQuery(int $days): Builder
    {
        return FilterSkipLog::where('last_skipped_at', '<', now()->subDays($days));
    }

    private static function decode(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $d = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $d : $value;
    }
}
