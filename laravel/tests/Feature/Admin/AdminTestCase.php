<?php

namespace Tests\Feature\Admin;

use App\Models\AdminPagePermission;
use App\Models\AdminUser;
use App\Models\FieldCoverageStat;
use App\Models\LotInspection;
use App\Models\NotificationEvent;
use App\Models\NotificationLog;
use App\Services\Notifications\NotificationState;
use Database\Factories\BotUserFactory;
use Database\Factories\FilterSkipLogFactory;
use Database\Factories\LotChangeFactory;
use Database\Factories\LotFactory;
use Database\Factories\NotificationRecipientFactory;
use Database\Factories\ParseFilterFactory;
use Database\Factories\ParseJobFactory;
use Database\Factories\TranslationFactory;
use Database\Factories\AdminUserFactory;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** Panel tests: migrated carbot_test, each test in a transaction, panel selected. */
abstract class AdminTestCase extends TestCase
{
    use RefreshesDatabaseOnce;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('panel'));
    }

    protected function super(array $attributes = []): AdminUser
    {
        return AdminUserFactory::new()->create($attributes);
    }

    /**
     * A limited admin; only $pages are enabled in admin_page_permissions
     * (one set for all limited admins), every other known key is disabled.
     *
     * @param  list<string>  $pages
     */
    protected function limited(array $pages = []): AdminUser
    {
        foreach (array_keys(AdminPagePermission::PAGES) as $key) {
            AdminPagePermission::updateOrCreate(['page_key' => $key], ['label' => $key, 'enabled' => in_array($key, $pages, true)]);
        }
        Once::flush();

        return AdminUserFactory::new()->limited()->create();
    }

    /**
     * A small realistic data set touching every table the panel reads.
     *
     * @return array{lot: \App\Models\Lot, inactive: \App\Models\Lot}
     */
    protected function seedSample(): array
    {
        $lot = LotFactory::new()->create(['raw_data' => ['ad_status' => 'ADVERTISE', 'pre_norm' => ['trim' => null, 'model_en' => null]]]);
        LotInspection::create(['lot_id' => $lot->id, 'source' => 'encar', 'cert_no' => 'C-1', 'inspection_date' => now()->subMonth()->toDateString(),
            'has_accident' => true, 'accident_detail' => "2025-01-01 [my-fault] ₩1,000,000", 'details' => ['recall' => false]]);
        $inactive = LotFactory::new()->inactive()->create(['trim' => null, 'trim_en' => null]);
        LotChangeFactory::new()->create(['lot_id' => $lot->id]);
        LotChangeFactory::new()->delisted()->create(['lot_id' => $inactive->id]);

        ParseJobFactory::new()->successful()->create(['created_at' => now()->subDay(), 'updated_at' => now()->subHours(12)]);
        ParseJobFactory::new()->running()->create(['triggered_by' => 'admin:test']);
        ParseJobFactory::new()->create(['type' => 'reparse', 'status' => 'done', 'target_lot_ids' => [$lot->id]]);

        ParseFilterFactory::new()->create(['name' => 'seed_rule_mileage']);
        ParseFilterFactory::new()->create(['name' => 'seed_rule_rental', 'field' => 'sell_type', 'operator' => 'eq', 'value' => '"rental"', 'priority' => 20]);
        FilterSkipLogFactory::new()->count(2)->create();

        TranslationFactory::new()->count(3)->create();
        TranslationFactory::new()->create(['source' => 'manual', 'category' => 'make', 'kr' => '현대', 'en' => 'Hyundai']);

        $user = BotUserFactory::new()->create();
        DB::table('searches')->insert(['user_id' => $user->id, 'make' => 'Hyundai', 'model' => 'Sonata', 'year_from' => 2019, 'year_to' => 2021,
            'price_max' => 25000000, 'query' => json_encode(['make' => 'Hyundai']), 'results_cnt' => 4, 'relaxed' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('favorites')->insert(['user_id' => $user->id, 'lot_id' => $lot->id, 'source' => 'encar',
            'lot_data' => json_encode(['title' => 'Hyundai Sonata', 'year' => 2019, 'price' => 21900000]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('subscriptions')->insert(['user_id' => $user->id, 'query' => json_encode(['make' => 'Hyundai', 'priceMax' => 25000000]),
            'new_lots_count' => 2, 'active' => 1, 'last_checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        NotificationRecipientFactory::new()->create();
        NotificationEvent::create(['event_key' => 'balance:proxy:low', 'type' => 'balance.proxy_low', 'status' => 'open', 'opened_at' => now()->subHour()]);
        NotificationLog::create(['type' => 'test', 'chat_id' => '100', 'ok' => true, 'text' => 'hi', 'created_at' => now()]);
        FieldCoverageStat::create(['source' => 'encar', 'field_name' => 'trim', 'total_lots' => 10, 'filled_lots' => 4, 'coverage_pct' => 40, 'computed_at' => now()]);

        // fresh balances so the Overview never calls the APIs
        app(NotificationState::class)->put('last_status', [
            'proxy' => ['ok' => true, 'gb' => 12.5, 'expires_at' => now()->addMonth()->toIso8601String(), 'at' => now()->toIso8601String()],
            'ai'    => ['ok' => true, 'amount' => 7.25, 'currency' => 'USD', 'at' => now()->toIso8601String()],
        ]);

        return ['lot' => $lot, 'inactive' => $inactive];
    }

    protected function signIn(?AdminUser $admin = null): AdminUser
    {
        $admin ??= $this->super();
        $this->actingAs($admin, 'admin');

        return $admin;
    }
}
