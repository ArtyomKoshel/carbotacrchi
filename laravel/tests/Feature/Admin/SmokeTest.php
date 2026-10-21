<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Overview;
use App\Filament\Pages\Platform;
use App\Filament\Widgets\LotChangesSummaryWidget;
use App\Models\Lot;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Every page of the panel opens. Pages and resource pages are read from the
 * panel itself, so a page added later is covered without touching this test;
 * a page with a route parameter it does not know fails until one is added here.
 */
class SmokeTest extends AdminTestCase
{
    /**
     * @param  bool  $withRecords  include pages that need a record (view pages)
     * @return list<string>
     */
    private function urls(bool $withRecords): array
    {
        /** @var Panel $panel */
        $panel = Filament::getPanel('panel');
        $urls = [];

        foreach ($panel->getPages() as $page) {
            $params = $this->params($page::getRoutePath($panel), $page);
            $urls[] = $page::getUrl($params);
            if ($page === Platform::class) {
                foreach (array_keys(Platform::TABS) as $tab) {
                    $urls[] = $page::getUrl($params + ['tab' => $tab]);
                }
            }
        }

        foreach ($panel->getResources() as $resource) {
            foreach ($resource::getPages() as $name => $registration) {
                $page = $registration->getPage();
                if (is_subclass_of($page, ViewRecord::class) || is_subclass_of($page, EditRecord::class)) {
                    if (!$withRecords) {
                        continue;
                    }
                    $urls[] = $resource::getUrl($name, ['record' => $this->recordFor($resource::getModel())]);
                    continue;
                }
                $urls[] = $resource::getUrl($name);
            }
        }

        return array_values(array_unique($urls));
    }

    private function params(string $path, string $page): array
    {
        preg_match_all('/\{(\w+)\??\}/', $path, $m);
        $known = ['source' => 'encar'];
        $unknown = array_diff($m[1], array_keys($known));
        $this->assertSame([], array_values($unknown), "{$page} has route parameters this test does not know; add them to SmokeTest::params()");

        return array_intersect_key($known, array_flip($m[1]));
    }

    private function recordFor(string $model): string
    {
        $record = $model::query()->first();
        $this->assertNotNull($record, "No {$model} seeded for its view page; extend AdminTestCase::seedSample()");

        return (string) $record->getKey();
    }

    public function test_the_panel_has_the_expected_pages(): void
    {
        $urls = $this->urls(false);

        // a floor, not a list: losing a page should be noticed
        $this->assertGreaterThanOrEqual(15, count($urls));
        foreach (['/admin', '/admin/lots', '/admin/platforms/encar', '/admin/translations', '/admin/logs'] as $path) {
            $this->assertContains(url($path), $urls);
        }
    }

    public function test_every_page_opens_with_data(): void
    {
        $this->seedSample();
        $this->signIn();

        foreach ($this->urls(true) as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_every_page_opens_on_an_empty_database(): void
    {
        $this->signIn();
        $this->assertSame(0, Lot::count());

        foreach ($this->urls(false) as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_every_widget_renders_with_data_and_empty(): void
    {
        // no fresh balance status yet: the balances widget asks the APIs
        Http::fake(['floppy.test/*' => Http::response([], 500), 'deepseek.test/*' => Http::response([], 500)]);
        $widgets = [...(new Overview())->getWidgets(), LotChangesSummaryWidget::class];
        $this->signIn();

        foreach ($widgets as $widget) {
            $this->assertNotEmpty(Livewire::withoutLazyLoading()->test($widget)->html(), $widget);
        }

        $this->seedSample();
        foreach ($widgets as $widget) {
            $this->assertNotEmpty(Livewire::withoutLazyLoading()->test($widget)->html(), $widget);
        }
    }
}
