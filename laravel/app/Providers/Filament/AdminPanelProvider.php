<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Pages\Overview;
use App\Http\Middleware\Panel\ShareLegacyAdminSession;
use App\Http\Middleware\Panel\UseRussianLocale;
use App\Models\AdminUser;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The new admin panel at /panel (Filament). It replaces the Blade pages under
 * /admin section by section; until a section moves, its menu item links to the
 * old page.
 */
class AdminPanelProvider extends PanelProvider
{
    public const GROUP_WORK = 'Работа';
    public const GROUP_PLATFORMS = 'Площадки';
    public const GROUP_DATA = 'Данные';
    public const GROUP_SEARCH_TEAM = 'Поиск и команда';
    public const GROUP_SYSTEM = 'Система';

    private const THEME = 'css/filament/panel/theme.css';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('panel')
            ->path('panel')
            ->authGuard('admin')
            ->login(Login::class)
            ->brandName('CarBot')
            ->colors([
                'primary' => Color::Indigo,
                'gray'    => Color::Zinc,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger'  => Color::Rose,
                'info'    => Color::Sky,
            ])
            ->font('Onest Variable', provider: LocalFontProvider::class)
            ->theme($this->themeUrl())
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make(self::GROUP_WORK),
                NavigationGroup::make(self::GROUP_PLATFORMS),
                NavigationGroup::make(self::GROUP_DATA),
                NavigationGroup::make(self::GROUP_SEARCH_TEAM),
                NavigationGroup::make(self::GROUP_SYSTEM),
            ])
            ->navigationItems($this->legacyNavigationItems())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Overview::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareLegacyAdminSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([
                UseRussianLocale::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    private function themeUrl(): string
    {
        $mtime = @filemtime(public_path(self::THEME));

        return asset(self::THEME) . ($mtime ? "?v={$mtime}" : '');
    }

    /**
     * Old /admin pages not moved to the panel yet. They open in a new tab and
     * follow the same page permissions as the old menu.
     *
     * @return array<NavigationItem>
     */
    private function legacyNavigationItems(): array
    {
        $items = [
            // [label, route, page key (null = super only), group]
            ['Задачи парсинга', 'admin.jobs', 'jobs', self::GROUP_PLATFORMS],
            ['Расписание', 'admin.schedules', 'schedules', self::GROUP_PLATFORMS],
            ['Правила отбора', 'admin.filters', 'filters', self::GROUP_PLATFORMS],
            ['Лог пропусков', 'admin.filter-skip-log.index', 'filter-skip-log', self::GROUP_PLATFORMS],
            ['Поля и покрытие', 'admin.fields', 'fields', self::GROUP_DATA],
            ['Изменения лотов', 'admin.changes', 'changes', self::GROUP_DATA],
            ['Настройки поиска', 'admin.bot-filters', 'bot-filters', self::GROUP_SEARCH_TEAM],
            ['Пользователи админки', 'admin.users', null, self::GROUP_SEARCH_TEAM],
            ['Уведомления', 'admin.notifications', null, self::GROUP_SYSTEM],
            ['Логи', 'admin.logs', 'logs', self::GROUP_SYSTEM],
            ['Старая админка', 'admin.dashboard', 'dashboard', self::GROUP_SYSTEM],
        ];

        return array_map(
            fn (array $item, int $i) => NavigationItem::make($item[0])
                ->url(fn () => route($item[1]), shouldOpenInNewTab: true)
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->group($item[3])
                ->sort(100 + $i)
                ->visible(fn () => self::admin()?->canSeePage($item[2]) ?? false),
            $items,
            array_keys($items),
        );
    }

    public static function admin(): ?AdminUser
    {
        $user = Auth::guard('admin')->user();

        return $user instanceof AdminUser ? $user : null;
    }
}
