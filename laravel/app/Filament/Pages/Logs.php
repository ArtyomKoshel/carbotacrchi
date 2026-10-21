<?php

namespace App\Filament\Pages;

use App\Providers\Filament\AdminPanelProvider;
use App\Services\LogFiles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** «Логи»: parser, job and application logs, newest lines first. */
class Logs extends Page
{
    public const LEVELS = ['ERROR', 'WARNING', 'INFO', 'DEBUG'];

    public const LIMITS = [200, 500, 1000, 5000];

    protected string $view = 'filament.pages.logs';

    protected static ?string $title = 'Логи';

    protected static ?string $navigationLabel = 'Логи';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_SYSTEM;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'logs';

    #[Url]
    public string $file = '';

    #[Url]
    public string $level = '';

    #[Url]
    public string $q = '';

    #[Url]
    public int $limit = 500;

    /** Re-read every 5 s */
    public bool $follow = false;

    public static function canAccess(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('logs') ?? false;
    }

    public function mount(): void
    {
        $files = $this->logs()->all();
        if (!$this->logs()->find($this->file)) {
            $this->file = $files[0]['key'] ?? '';
        }
        if (!in_array($this->limit, self::LIMITS, true)) {
            $this->limit = 500;
        }
    }

    private function logs(): LogFiles
    {
        return app(LogFiles::class);
    }

    /** @return array<string, list<array>> group => files */
    public function files(): array
    {
        return collect($this->logs()->all())->groupBy('group')->map->all()->all();
    }

    public function current(): ?array
    {
        return $this->logs()->find($this->file);
    }

    /** @return list<string> */
    public function lines(): array
    {
        $f = $this->current();

        return $f ? $this->logs()->tail($f['path'], $this->limit, $this->level ?: null, $this->q ?: null) : [];
    }

    protected function getHeaderActions(): array
    {
        $super = fn () => AdminPanelProvider::admin()?->isSuper() ?? false;

        return [
            Action::make('download')
                ->label('Скачать')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => $this->current() !== null)
                ->action(fn () => $this->download()),
            Action::make('clearParser')
                ->label('Очистить лог парсера')
                ->icon(Heroicon::OutlinedTrash)
                ->color('gray')
                ->visible($super)
                ->requiresConfirmation()
                ->modalHeading('Очистить лог парсера?')
                ->modalDescription('Основной файл станет пустым, его ротации (.1 … .10) удалятся. Логи отдельных задач и приложения не трогаются. Вернуть нельзя.')
                ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
                ->action(function () {
                    $n = $this->logs()->clearParser();
                    Notification::make()->title("Очищено файлов: {$n}")->success()->send();
                }),
            Action::make('clearJobs')
                ->label('Удалить логи задач')
                ->icon(Heroicon::OutlinedTrash)
                ->color('gray')
                ->visible($super)
                ->requiresConfirmation()
                ->modalHeading('Удалить логи задач парсинга?')
                ->modalDescription('Удалятся файлы job-N.log всех завершённых задач. Логи задач в очереди, идущих или прерванных остаются. Вернуть нельзя.')
                ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
                ->action(function () {
                    $r = $this->logs()->clearJobs();
                    Notification::make()->title("Удалено: {$r['deleted']}" . ($r['kept'] ? ", оставлено активных: {$r['kept']}" : ''))->success()->send();
                }),
        ];
    }

    /** The whole file, or only the filtered lines (oldest first) when a filter is set. */
    public function download(): StreamedResponse | \Symfony\Component\HttpFoundation\BinaryFileResponse | null
    {
        $f = $this->current();
        if (!$f) {
            return null;
        }
        if (!$this->level && !$this->q) {
            return response()->download($f['path'], $f['label']);
        }
        $lines = array_reverse($this->logs()->tail($f['path'], 100000, $this->level ?: null, $this->q ?: null));

        return response()->streamDownload(function () use ($lines) {
            foreach ($lines as $line) {
                echo $line, PHP_EOL;
            }
        }, pathinfo($f['label'], PATHINFO_FILENAME) . '-filtered-' . now()->format('Ymd-His') . '.log', ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
