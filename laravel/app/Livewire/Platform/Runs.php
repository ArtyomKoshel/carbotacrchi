<?php

namespace App\Livewire\Platform;

use App\Filament\Resources\Lots\LotFormat;
use App\Models\ParseJob;
use App\Services\ParseJobLog;
use App\Services\ParseRuns;
use App\Support\JobFormat;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;

/** «Прогоны»: the run in progress (polled), launch/cancel, run history. */
class Runs extends PlatformComponent implements HasTable
{
    use InteractsWithTable;

    protected const PAGE_KEY = 'jobs';

    public function render(): View
    {
        return view('livewire.platform.runs', [
            'current' => app(ParseRuns::class)->current($this->source),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ParseJob::query()->where('source', $this->source))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->prefix('#'),
                TextColumn::make('kind')->label('Прогон')
                    ->state(fn (ParseJob $record) => JobFormat::kind($record))
                    ->description(fn (ParseJob $record) => JobFormat::triggeredBy($record->triggered_by)),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn (?string $state) => JobFormat::status($state))
                    ->color(fn (?string $state) => JobFormat::statusColor($state)),
                TextColumn::make('counted')->label('Засчитан')
                    ->state(fn (ParseJob $record) => $this->countedLabel($record))
                    ->tooltip(fn (ParseJob $record) => $this->countedTooltip($record))
                    ->color(fn (ParseJob $record) => $this->countedLabel($record) === 'да' ? null : 'gray'),
                TextColumn::make('created_at')->label('Начало')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('duration')->label('Длительность')
                    ->state(fn (ParseJob $record) => JobFormat::duration(ParseRuns::stats($record)['elapsed'])),
                TextColumn::make('lots')->label('Лоты')->alignEnd()
                    ->state(fn (ParseJob $record) => $this->lotsLine($record))
                    ->description(fn (ParseJob $record) => $record->type === 'full' ? $this->changesLine($record) : null),
                TextColumn::make('errors')->label('Ошибки')->alignEnd()
                    ->state(fn (ParseJob $record) => LotFormat::number(ParseRuns::stats($record)['errors']))
                    ->color(fn (ParseJob $record) => ParseRuns::stats($record)['errors'] > 0 ? 'danger' : null),
                TextColumn::make('proxy')->label('Прокси')->alignEnd()
                    ->state(fn (ParseJob $record) => ($b = ParseRuns::stats($record)['proxy_bytes']) > 0 ? ParseRuns::gb($b) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label('Тип')->options(['full' => 'Прогоны', 'reparse' => 'Перепарсинг']),
                SelectFilter::make('status')->label('Статус')
                    ->options(collect(JobFormat::STATUS_COLORS)->keys()->mapWithKeys(fn ($s) => [$s => JobFormat::status($s)])->all()),
            ])
            ->headerActions([$this->launchAction()])
            ->recordAction('details')
            ->recordActions([
                Action::make('details')
                    ->label('Подробности')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedEye)
                    ->slideOver()
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalHeading(fn (ParseJob $record) => "Прогон #{$record->id}")
                    ->modalDescription(fn (ParseJob $record) => JobFormat::kind($record) . ' · ' . JobFormat::triggeredBy($record->triggered_by))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть')
                    ->schema(fn (ParseJob $record) => $this->detailsSchema($record)),
            ])
            ->paginationPageOptions([25, 50])
            ->emptyStateHeading('Прогонов ещё не было');
    }

    public function launchAction(): Action
    {
        $runs = app(ParseRuns::class);

        return Action::make('launch')
            ->label('Запустить парсинг')
            ->icon(Heroicon::OutlinedPlay)
            ->modalHeading('Запустить парсинг')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Поставить в очередь')
            ->schema([
                Radio::make('mode')
                    ->label('Что парсить')
                    ->options([
                        ParseRuns::MODE_FULL   => 'Полный прогон',
                        ParseRuns::MODE_TEST   => 'Тестовый (' . ParseRuns::TEST_PAGES . ' страниц)',
                        ParseRuns::MODE_MAKERS => 'Только марки',
                    ])
                    ->descriptions([
                        ParseRuns::MODE_FULL   => 'Все лоты площадки. Засчитывается как успешный, если охват ≥ ' . config('lifecycle.min_coverage') . ' %: после него лоты, которых не было, снимаются.',
                        ParseRuns::MODE_TEST   => 'Проверить, что парсер работает. Не засчитывается и ничего не снимает.',
                        ParseRuns::MODE_MAKERS => 'По отдельной задаче на каждую марку, одна за другой. Не засчитываются и ничего не снимают.',
                    ])
                    ->default(ParseRuns::MODE_FULL)
                    ->required()
                    ->live(),
                Select::make('makers')
                    ->label('Марки')
                    ->multiple()
                    ->searchable()
                    ->options(fn () => $runs->makers($this->source))
                    ->visible(fn (Get $get) => $get('mode') === ParseRuns::MODE_MAKERS)
                    ->required(fn (Get $get) => $get('mode') === ParseRuns::MODE_MAKERS)
                    ->live(),
                Text::make(fn (Get $get) => $this->estimateText($get('mode'), (array) $get('makers'))),
                Text::make(fn () => $this->runningNotice()),
            ])
            ->action(function (array $data) use ($runs) {
                $jobs = $runs->launch($this->source, $data['mode'], (array) ($data['makers'] ?? []), $this->adminName());
                Notification::make()
                    ->title(count($jobs) === 1 ? "Прогон #{$jobs[0]->id} в очереди" : 'В очереди: ' . count($jobs) . ' задач')
                    ->body('Парсер проверяет очередь каждые 15 секунд.')
                    ->success()
                    ->send();
            });
    }

    /** Shown in the launch dialog: a new job waits for the one the parser is on. */
    public function runningNotice(): ?string
    {
        $cur = app(ParseRuns::class)->current($this->source);

        return $cur
            ? "Сейчас идёт прогон #{$cur->id} (" . JobFormat::status($cur->status) . '). Новые задачи встанут в очередь за ним.'
            : null;
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Остановить')
            ->icon(Heroicon::OutlinedStop)
            ->color('gray')
            ->requiresConfirmation()
            ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
            ->modalHeading(fn (array $arguments) => 'Остановить прогон #' . ($arguments['job'] ?? '') . '?')
            ->modalDescription('Парсер прервёт задачу при следующей проверке. Уже записанные лоты останутся. Прогон не засчитается как успешный, поэтому пропавшие лоты по нему сняты не будут.')
            ->modalSubmitActionLabel('Остановить')
            ->action(function (array $arguments) {
                $job = ParseJob::where('source', $this->source)->find($arguments['job'] ?? 0);
                if ($job && app(ParseRuns::class)->cancel($job)) {
                    Notification::make()->title("Прогон #{$job->id} остановлен")->success()->send();
                } else {
                    Notification::make()->title('Прогон уже завершился')->warning()->send();
                }
            });
    }

    private function estimateText(?string $mode, array $makers): ?HtmlString
    {
        $est = app(ParseRuns::class)->estimateProxy($this->source, (string) $mode, $makers);
        if ($est === null) {
            return $mode === ParseRuns::MODE_MAKERS && $makers === []
                ? null
                : new HtmlString('Оценки трафика прокси нет: не было завершённого полного прогона с замером.');
        }

        return new HtmlString('Прокси: примерно <strong>' . e(ParseRuns::gb($est['bytes'])) . '</strong> (' . e($est['basis']) . ').');
    }

    private function lotsLine(ParseJob $job): string
    {
        if ($job->type === 'reparse') {
            return LotFormat::number(count((array) $job->target_lot_ids));
        }
        $s = ParseRuns::stats($job);

        return LotFormat::number($s['done']) . ($s['total'] > 0 ? ' / ' . LotFormat::number($s['total']) : '');
    }

    private function changesLine(ParseJob $job): string
    {
        $s = ParseRuns::stats($job);

        return '+' . LotFormat::number($s['new']) . ' новых' . ($s['stale'] !== null ? ' · снято ' . LotFormat::number($s['stale']) : '');
    }

    private function countedLabel(ParseJob $job): string
    {
        if ($job->type !== 'full' || $job->status !== 'done') {
            return '—';
        }

        return app(ParseRuns::class)->rejection($job) === null ? 'да' : 'нет';
    }

    private function countedTooltip(ParseJob $job): ?string
    {
        if ($this->countedLabel($job) !== 'нет') {
            return $this->countedLabel($job) === 'да' ? 'Успешный полный прогон: по нему снимаются пропавшие лоты' : null;
        }

        return self::rejectionText((string) app(ParseRuns::class)->rejection($job));
    }

    /** lots:lifecycle reasons in Russian */
    public static function rejectionText(string $reason): string
    {
        return match (true) {
            str_starts_with($reason, 'filtered')      => 'Частичный прогон (марка или лимит страниц)',
            str_starts_with($reason, 'parser reported') => 'Парсер отметил прогон как неполный',
            str_starts_with($reason, 'no coverage')   => 'Нет данных об охвате',
            str_starts_with($reason, 'coverage')      => 'Охват ниже порога: ' . str_replace('coverage ', '', $reason),
            str_starts_with($reason, 'wrote')         => 'Записано меньше половины найденного: ' . str_replace('wrote ', '', $reason),
            default                                   => $reason,
        };
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    private function detailsSchema(ParseJob $job): array
    {
        $s = ParseRuns::stats($job);
        $r = (array) ($job->result ?? []);
        $num = fn ($v) => $v === null ? '—' : LotFormat::number((int) $v);

        return [
            Grid::make(3)->schema([
                TextEntry::make('st')->label('Статус')->state(JobFormat::status($job->status))->badge()->color(JobFormat::statusColor($job->status)),
                TextEntry::make('cnt')->label('Засчитан')->state($this->countedLabel($job))->helperText($this->countedTooltip($job)),
                TextEntry::make('dur')->label('Длительность')->state(JobFormat::duration($s['elapsed'])),
                TextEntry::make('lots')->label('Лоты (обработано / на площадке)')->state($this->lotsLine($job)),
                TextEntry::make('cov')->label('Охват')->state(isset($r['coverage_pct']) ? $r['coverage_pct'] . ' %' : '—'),
                TextEntry::make('written')->label('Записано в базу')->state($num($r['lots_written'] ?? null)),
                TextEntry::make('new')->label('Новые')->state($num($s['new'])),
                TextEntry::make('upd')->label('Обновлено')->state($num($s['updated'])),
                TextEntry::make('filt')->label('Отсеяно правилами')->state($num($s['filtered'])),
                TextEntry::make('stale')->label('Снято с продажи')->state($num($s['stale'])),
                TextEntry::make('err')->label('Ошибки')->state($num($s['errors'])),
                TextEntry::make('proxy')->label('Прокси')->state($s['proxy_bytes'] > 0 ? ParseRuns::gb($s['proxy_bytes']) : '—'),
            ]),
            Section::make('Фазы')->compact()->visible(!empty($r['phases']))->schema([
                ViewEntry::make('phases')->hiddenLabel()->view('livewire.platform.partials.phases', ['phases' => $r['phases'] ?? []]),
            ]),
            Section::make('Сработавшие правила отбора')->compact()->visible(!empty($r['filter_rules']))->schema([
                ViewEntry::make('rules')->hiddenLabel()->view('livewire.platform.partials.key-counts', ['counts' => $r['filter_rules'] ?? []]),
            ]),
            Section::make('Ошибки')->compact()->visible(!empty($r['error_types']) || !empty($r['error_log']) || !empty($r['error']))->schema([
                TextEntry::make('error')->label('Ошибка задачи')->state($r['error'] ?? null)->visible(!empty($r['error']))->fontFamily('mono'),
                ViewEntry::make('etypes')->label('По типам')->view('livewire.platform.partials.key-counts', ['counts' => $r['error_types'] ?? []])->visible(!empty($r['error_types'])),
                ViewEntry::make('elog')->label('Последние')->view('livewire.platform.partials.lines', ['lines' => array_slice((array) ($r['error_log'] ?? []), -20)])->visible(!empty($r['error_log'])),
            ]),
            Section::make('Лог задачи')->compact()->collapsible()->collapsed($job->status !== 'error')->schema([
                ViewEntry::make('log')->hiddenLabel()->view('livewire.platform.partials.lines', [
                    'lines' => ($log = ParseJobLog::tail($job, 300))['lines'],
                    'empty' => $log['error'] ?? 'Лог пуст.',
                ]),
            ]),
        ];
    }
}
