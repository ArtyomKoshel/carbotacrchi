<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BalancesWidget;
use App\Filament\Widgets\IncidentsWidget;
use App\Filament\Widgets\PlatformHealthWidget;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;

/** Panel home: is parsing healthy, is anything on fire, is there money for proxies and AI. */
class Overview extends Dashboard
{
    protected static ?string $title = 'Обзор';

    protected static ?string $navigationLabel = 'Обзор';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedHome;

    protected static string | \UnitEnum | null $navigationGroup = AdminPanelProvider::GROUP_WORK;

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return AdminPanelProvider::admin()?->canSeePage('dashboard') ?? false;
    }

    public function getWidgets(): array
    {
        return [
            PlatformHealthWidget::class,
            IncidentsWidget::class,
            BalancesWidget::class,
        ];
    }
}
