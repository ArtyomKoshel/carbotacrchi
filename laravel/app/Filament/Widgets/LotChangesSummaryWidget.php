<?php

namespace App\Filament\Widgets;

use App\Support\AdminUiLabels;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Above «Изменения лотов»: changes per day for 14 days by event (what the old
 * /admin/changes and /admin/stats charts showed) and the lots that changed
 * most in 7 days. Both read the (recorded_at, event) index range.
 */
class LotChangesSummaryWidget extends Widget
{
    protected string $view = 'filament.widgets.lot-changes-summary';

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $rows = DB::table('lot_changes')
            ->where('recorded_at', '>=', now()->subDays(13)->startOfDay())
            ->groupByRaw('DATE(recorded_at), event')
            ->selectRaw('DATE(recorded_at) AS day, event, COUNT(*) AS n')
            ->get();

        $days = collect(range(13, 0))->map(fn ($i) => now()->subDays($i)->toDateString());
        $events = $rows->pluck('event')->unique()->sort()->values();
        $byDay = $days->mapWithKeys(fn ($d) => [$d => $rows->where('day', $d)->pluck('n', 'event')->map(fn ($n) => (int) $n)->all()]);

        $top = DB::table('lot_changes')
            ->where('recorded_at', '>=', now()->subDays(7))
            ->groupBy('lot_id')
            ->selectRaw('lot_id, COUNT(*) AS n')
            ->orderByDesc('n')
            ->limit(8)
            ->get();

        return [
            'days'   => $byDay,
            'events' => $events->mapWithKeys(fn ($e) => [$e => AdminUiLabels::event($e)])->all(),
            'max'    => max(1, $byDay->map(fn ($e) => array_sum($e))->max()),
            'top'    => $top,
        ];
    }
}
