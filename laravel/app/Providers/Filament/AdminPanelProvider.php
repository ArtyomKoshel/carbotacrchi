<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Pages\Overview;
use App\Http\Middleware\Panel\UseRussianLocale;
use App\Models\AdminUser;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Theme;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The admin panel at /admin (Filament). It replaced the Blade admin in
 * 2026-10; old bookmarks redirect from routes/web.php.
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
            ->path('admin')
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
            // Resolved per request: at boot asset() does not yet know the request came over
            // https through Railway's proxy, and an http stylesheet is blocked on an https page.
            ->theme(Theme::make('app')->html(fn () => $this->themeUrl()))
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make(self::GROUP_WORK),
                NavigationGroup::make(self::GROUP_PLATFORMS),
                NavigationGroup::make(self::GROUP_DATA),
                NavigationGroup::make(self::GROUP_SEARCH_TEAM),
                NavigationGroup::make(self::GROUP_SYSTEM),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Overview::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
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

    public static function admin(): ?AdminUser
    {
        $user = Auth::guard('admin')->user();

        return $user instanceof AdminUser ? $user : null;
    }
}
