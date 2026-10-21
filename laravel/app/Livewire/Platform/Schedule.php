<?php

namespace App\Livewire\Platform;

use App\Models\ParserSchedule;
use App\Services\ParseRuns;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;

/**
 * «Расписание»: parser_schedules row of the platform. The parser re-reads it
 * every minute (parser/scheduler.py). schedule is '' (every interval_minutes),
 * 'interval:N' or 'cron:<5 fields>'.
 */
class Schedule extends PlatformComponent
{
    protected const PAGE_KEY = 'schedules';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(string $source): void
    {
        parent::mount($source);

        $row = ParserSchedule::where('source', $source)->first();
        $schedule = (string) ($row->schedule ?? '');
        $isCron = str_starts_with($schedule, 'cron:');

        $this->form->fill([
            'enabled'          => (bool) ($row->enabled ?? false),
            'kind'             => $isCron ? 'cron' : 'interval',
            'interval_minutes' => str_starts_with($schedule, 'interval:') ? (int) substr($schedule, 9) : (int) ($row->interval_minutes ?? 900),
            'cron'             => $isCron ? trim(substr($schedule, 5)) : '0 3 * * *',
            'max_pages'        => (int) ($row->max_pages ?? 0),
            'maker_filter'     => $row->maker_filter ?? null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Плановый прогон')
                    ->description('Парсер перечитывает расписание раз в минуту. Ручные запуски на вкладке «Прогоны» от него не зависят.')
                    ->schema([
                        Toggle::make('enabled')->label('Запускать по расписанию')->live(),
                        Radio::make('kind')
                            ->label('Когда')
                            ->options(['interval' => 'Через равные промежутки', 'cron' => 'По расписанию cron'])
                            ->inline()
                            ->live(),
                        TextInput::make('interval_minutes')
                            ->label('Каждые, минут')
                            ->numeric()->minValue(10)->maxValue(10080)
                            ->helperText(fn (Get $get) => self::intervalHint((int) $get('interval_minutes')))
                            ->live(onBlur: true)
                            ->visible(fn (Get $get) => $get('kind') === 'interval')
                            ->required(fn (Get $get) => $get('kind') === 'interval'),
                        TextInput::make('cron')
                            ->label('Cron (минута час день месяц день-недели, UTC)')
                            ->placeholder('0 3 * * *')
                            ->rule('regex:/^\S+(\s+\S+){4}$/')
                            ->validationMessages(['regex' => 'Нужно 5 полей через пробел, например «0 3 * * *».'])
                            ->visible(fn (Get $get) => $get('kind') === 'cron')
                            ->required(fn (Get $get) => $get('kind') === 'cron'),
                        TextInput::make('max_pages')
                            ->label('Лимит страниц')
                            ->numeric()->minValue(0)
                            ->helperText('0 — без лимита. С лимитом прогон частичный: он не засчитывается и пропавшие лоты по нему не снимаются.'),
                        Select::make('maker_filter')
                            ->label('Только марка')
                            ->placeholder('Все марки')
                            ->searchable()
                            ->options(fn () => app(ParseRuns::class)->makers($this->source))
                            ->helperText('С маркой прогон тоже частичный.'),
                        Text::make('Сейчас плановый запуск выключен: парсер запускается только вручную.')
                            ->visible(fn (Get $get) => !$get('enabled')),
                        Actions::make([
                            Action::make('save')
                                ->label('Сохранить')
                                ->requiresConfirmation(fn (Get $get) => (bool) $get('enabled'))
                                ->modalHeading('Включить плановый прогон?')
                                ->modalDescription('Каждый полный прогон расходует трафик прокси (см. «Прогоны»). Первый запуск будет примерно через минуту после сохранения.')
                                ->action(fn () => $this->save()),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        ParserSchedule::updateOrCreate(['source' => $this->source], [
            'enabled'          => (bool) $data['enabled'],
            'schedule'         => $data['kind'] === 'cron' ? 'cron:' . preg_replace('/\s+/', ' ', trim($data['cron'])) : '',
            'interval_minutes' => (int) ($data['interval_minutes'] ?: 900),
            'max_pages'        => (int) ($data['max_pages'] ?? 0),
            'maker_filter'     => $data['maker_filter'] ?: null,
        ]);

        Notification::make()->title('Расписание сохранено')->body('Парсер применит его в течение минуты.')->success()->send();
    }

    public function render(): View
    {
        return view('livewire.platform.form');
    }

    private static function intervalHint(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return 'То есть ' . ($h ? "{$h} ч" : '') . ($h && $m ? ' ' : '') . ($m ? "{$m} мин" : '') . '.';
    }
}
