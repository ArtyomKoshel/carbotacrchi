<?php

namespace App\Filament\Pages;

use App\Providers\Filament\AdminPanelProvider;
use App\Support\Platforms;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * One page per platform (lot source): runs, schedule, selection rules, skip
 * log, field coverage. Each tab is its own Livewire component.
 */
class Platform extends Page
{
    /** tab => [label, permission page key] */
    public const TABS = [
        'runs'     => ['Прогоны', 'jobs'],
        'schedule' => ['Расписание', 'schedules'],
        'rules'    => ['Правила отбора', 'filters'],
        'skips'    => ['Лог пропусков', 'filter-skip-log'],
        'fields'   => ['Поля', 'fields'],
    ];

    protected string $view = 'filament.pages.platform';

    protected static ?string $slug = 'platforms';

    public string $source = '';

    #[Url]
    public string $tab = 'runs';

    public function mount(string $source): void
    {
        abort_unless(Platforms::exists($source), 404);
        $this->source = $source;

        $tabs = $this->visibleTabs();
        abort_if($tabs === [], 403);
        if (!isset($tabs[$this->tab])) {
            $this->tab = array_key_first($tabs);
        }
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/platforms/{source}';
    }

    public static function canAccess(): bool
    {
        $admin = AdminPanelProvider::admin();

        return $admin !== null && collect(self::TABS)->contains(fn (array $tab) => $admin->canSeePage($tab[1]));
    }

    /** @return array<string, string> tab => label, only tabs this admin may open */
    public function visibleTabs(): array
    {
        $admin = AdminPanelProvider::admin();

        return collect(self::TABS)
            ->filter(fn (array $tab) => $admin?->canSeePage($tab[1]))
            ->map(fn (array $tab) => $tab[0])
            ->all();
    }

    public function getTitle(): string | Htmlable
    {
        return Platforms::label($this->source);
    }

    /** One menu item per platform under «Площадки». */
    public static function getNavigationItems(): array
    {
        return array_map(
            fn (string $source, int $i) => NavigationItem::make(Platforms::label($source))
                ->key("platform-{$source}")
                ->group(AdminPanelProvider::GROUP_PLATFORMS)
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->sort($i)
                ->url(fn () => static::getUrl(['source' => $source]))
                ->isActiveWhen(fn () => request()->routeIs(static::getRouteName()) && request()->route('source') === $source),
            Platforms::all(),
            array_keys(Platforms::all()),
        );
    }
}
