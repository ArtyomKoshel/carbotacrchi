<?php

namespace App\Filament\Widgets;

use App\Models\NotificationEvent;
use Filament\Widgets\Widget;

/** «Обзор»: incidents notifications:watch has open (stalled run, DB down, low balance…). */
class IncidentsWidget extends Widget
{
    protected string $view = 'filament.widgets.incidents';

    protected static ?int $sort = 2;

    protected function getViewData(): array
    {
        return [
            'events' => NotificationEvent::where('status', 'open')->orderByDesc('opened_at')->limit(20)->get(),
            'types'  => collect(config('notifications.types'))->map(fn (array $t) => $t['label'])->all(),
        ];
    }
}
