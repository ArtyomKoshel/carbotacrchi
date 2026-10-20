<?php

namespace App\Filament\Widgets;

use App\Services\PlatformHealth;
use App\Support\Platforms;
use Filament\Widgets\Widget;

/** «Обзор»: one card per platform — runs, lots, data quality. Loads after the page. */
class PlatformHealthWidget extends Widget
{
    protected string $view = 'filament.widgets.platform-health';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 1;

    protected function getViewData(): array
    {
        $health = app(PlatformHealth::class);

        return [
            'platforms' => array_map(fn (string $source) => $health->of($source), Platforms::all()),
        ];
    }
}
