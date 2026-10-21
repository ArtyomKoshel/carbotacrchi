<?php

namespace Tests\Feature\Admin;

use App\Filament\Widgets\BalancesWidget;
use App\Filament\Widgets\IncidentsWidget;
use App\Filament\Widgets\PlatformHealthWidget;
use App\Models\NotificationEvent;
use App\Services\Notifications\NotificationState;
use App\Services\PlatformHealth;
use Database\Factories\LotChangeFactory;
use Database\Factories\LotFactory;
use Database\Factories\ParseJobFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** Обзор widgets, with every external API faked. */
class OverviewTest extends AdminTestCase
{
    private function balances()
    {
        return Livewire::withoutLazyLoading()->test(BalancesWidget::class);
    }

    public function test_fresh_watcher_status_is_shown_without_calling_apis(): void
    {
        $this->signIn();
        Http::fake();
        app(NotificationState::class)->put('last_status', [
            'proxy' => ['ok' => true, 'gb' => 1.5, 'at' => now()->subMinutes(10)->toIso8601String()],
            'ai'    => ['ok' => true, 'amount' => 7.25, 'currency' => 'USD', 'at' => now()->subMinutes(10)->toIso8601String()],
        ]);

        $this->balances()->assertSee('1,50 ГБ')->assertSee('7,25 USD')->assertSee('ниже порога'); // default proxy threshold 2 GB

        Http::assertNothingSent();
    }

    public function test_stale_status_asks_the_apis(): void
    {
        $this->signIn();
        app(NotificationState::class)->put('last_status', ['proxy' => ['ok' => true, 'gb' => 99, 'at' => now()->subHours(3)->toIso8601String()]]);
        Http::fake([
            'floppy.test/v2/*'  => Http::response(['total' => ['traffic' => ['availableGb' => 13.99]]]),
            'deepseek.test/*'   => Http::response(['is_available' => true, 'balance_infos' => [['currency' => 'USD', 'total_balance' => '4.10']]]),
        ]);

        $this->balances()->assertSee('13,99 ГБ')->assertSee('4,10 USD')->assertDontSee('99,00');
    }

    public function test_api_errors_and_timeouts_degrade_gracefully(): void
    {
        $this->signIn();
        Http::fake([
            'floppy.test/*'   => Http::response(['message' => 'boom'], 500),
            'deepseek.test/*' => fn () => throw new ConnectionException('Operation timed out after 15000 milliseconds'),
        ]);

        $this->balances()->assertSee('не удалось проверить');
    }

    public function test_platform_health_numbers(): void
    {
        $this->signIn();
        LotFactory::new()->count(2)->create(['created_at' => now()->subHours(3)]);  // new in 24 h
        LotFactory::new()->create(['created_at' => now()->subDays(3)]);
        $gone = LotFactory::new()->inactive()->create();
        LotChangeFactory::new()->delisted()->create(['lot_id' => $gone->id, 'recorded_at' => now()->subHours(5)]);
        LotChangeFactory::new()->delisted()->create(['lot_id' => $gone->id, 'recorded_at' => now()->subDays(2)]);
        $ok = ParseJobFactory::new()->successful()->create();

        $h = app(PlatformHealth::class)->of('encar');

        $this->assertSame(3, $h['active']);
        $this->assertSame(2, $h['new24']);
        $this->assertSame(1, $h['delisted24']);
        $this->assertSame($ok->id, $h['last']->id);
        $this->assertSame(3, end($h['series'])['active'], 'today = live count');

        Livewire::withoutLazyLoading()->test(PlatformHealthWidget::class)->assertSee('Encar')->assertSee('+2 новых');
    }

    public function test_open_incidents_are_listed(): void
    {
        $this->signIn();
        NotificationEvent::create(['event_key' => 'job:9:stalled', 'type' => 'jobs.failed', 'status' => 'open', 'opened_at' => now()->subHour()]);
        NotificationEvent::create(['event_key' => 'old', 'type' => 'jobs.failed', 'status' => 'resolved', 'opened_at' => now()->subDay()]);

        Livewire::withoutLazyLoading()->test(IncidentsWidget::class)->assertSee('job:9:stalled')->assertDontSee('>old<', false);
    }
}
