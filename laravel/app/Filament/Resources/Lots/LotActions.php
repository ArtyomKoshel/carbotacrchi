<?php

namespace App\Filament\Resources\Lots;

use App\Filament\Resources\Lots\Pages\ViewLot;
use App\Models\Lot;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\LotReparseQueue;
use App\Support\LotLinks;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class LotActions
{
    /** What the parser's run_reparse does (parser/parsers/encar: accident + inspection re-enrich). */
    private const CONSEQUENCES = 'Парсер заново запросит у площадки историю (ДТП, страховые случаи, владельцы) и отчёт осмотра. '
        . 'Изменённые поля попадут во вкладку «История». После этого снова применятся правила отбора после осмотра: '
        . 'лот, который им больше не проходит, будет снят. Цена и статус обновятся при следующем полном прогоне. '
        . 'Задача идёт параллельно плановому прогону; лоты, которые уже ждут перепарсинга, пропускаются.';

    public static function bulkLimit(): int
    {
        return LotReparseQueue::MAX_LOTS;
    }

    /** The old admin's «Репарсинг» page permission. */
    public static function canReparse(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('lots') ?? false;
    }

    public static function bulkReparse(): BulkAction
    {
        return BulkAction::make('reparse')
            ->label('Перепарсить')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedArrowPath)
            ->modalHeading(fn (Collection $records) => 'Перепарсить ' . self::lotsCount($records->count()) . '?')
            ->modalDescription(self::CONSEQUENCES . ' Не больше ' . self::bulkLimit() . ' лотов за раз.')
            ->modalSubmitActionLabel('Поставить в очередь')
            ->action(fn (Collection $records) => self::notify(app(LotReparseQueue::class)->queue($records)))
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => self::canReparse());
    }

    public static function reparse(): Action
    {
        return Action::make('reparse')
            ->label('Перепарсить')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedArrowPath)
            ->modalHeading(fn (Lot $record) => "Перепарсить лот {$record->id}?")
            ->modalDescription(self::CONSEQUENCES)
            ->modalSubmitActionLabel('Поставить в очередь')
            ->action(fn (Lot $record) => self::notify(app(LotReparseQueue::class)->queue([$record])))
            ->visible(fn () => self::canReparse());
    }

    public static function openOnPlatform(): Action
    {
        return Action::make('openOnPlatform')
            ->label('Открыть на площадке')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Lot $record) => LotLinks::platformUrl($record->source, $record->id, $record->lot_url), shouldOpenInNewTab: true)
            ->visible(fn (Lot $record) => LotLinks::platformUrl($record->source, $record->id, $record->lot_url) !== null);
    }

    /** From the slide-over to the lot's own page (a link to share). */
    public static function openPage(): Action
    {
        return Action::make('openPage')
            ->label('Страница лота')
            ->icon(Heroicon::OutlinedArrowsPointingOut)
            ->color('gray')
            ->url(fn (Lot $record) => LotResource::getUrl('view', ['record' => $record]))
            ->hidden(fn ($livewire) => $livewire instanceof ViewLot);
    }

    /** @param  array{queued: int, skipped: int, jobs: list<int>}  $result */
    private static function notify(array $result): void
    {
        if ($result['queued'] === 0) {
            Notification::make()
                ->title('Уже в очереди')
                ->body('Эти лоты уже ждут перепарсинга, новая задача не создана.')
                ->warning()
                ->send();

            return;
        }

        $jobs = implode(', ', array_map(fn (int $id) => "#{$id}", $result['jobs']));
        $body = "Задача {$jobs}.";
        if ($result['skipped'] > 0) {
            $body .= ' Уже были в очереди: ' . self::lotsCount($result['skipped']) . '.';
        }

        Notification::make()
            ->title('В очереди: ' . self::lotsCount($result['queued']))
            ->body($body)
            ->success()
            ->send();
    }

    public static function lotsCount(int $n): string
    {
        $mod10  = $n % 10;
        $mod100 = $n % 100;
        $word = match (true) {
            $mod10 === 1 && $mod100 !== 11 => 'лот',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'лота',
            default => 'лотов',
        };

        return LotFormat::number($n) . ' ' . $word;
    }
}
