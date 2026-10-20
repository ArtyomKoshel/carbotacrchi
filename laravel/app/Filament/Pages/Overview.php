<?php

namespace App\Filament\Pages;

use App\Providers\Filament\AdminPanelProvider;
use Filament\Pages\Dashboard;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Panel home. Phase 2 fills it with the parsing and data-quality summary. */
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

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make('Обзор появится на следующем этапе')
                ->description('Здесь будет сводка: последние прогоны по площадкам, расписание, лоты за сутки и очередь аномалий. Пока откройте «Лоты» или старые страницы в меню.')
                ->icon(Heroicon::OutlinedPresentationChartLine),
        ]);
    }
}
