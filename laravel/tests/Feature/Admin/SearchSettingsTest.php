<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\SearchSettings;
use App\Models\BotFilterSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** bot_filter_settings: autosave, human tolerances, reset, the parse check. */
class SearchSettingsTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (BotFilterSetting::count() === 0) {
            Artisan::call('db:seed', ['--class' => 'BotFilterSettingsSeeder', '--force' => true]);
        }
    }

    private function row(string $field): BotFilterSetting
    {
        return BotFilterSetting::where('field_name', $field)->firstOrFail();
    }

    public function test_absolute_tolerance_autosaves_and_reads_back(): void
    {
        $this->signIn();
        $year = $this->row('year');

        Livewire::test(SearchSettings::class)
            ->set("rows.{$year->id}.tolerance_type", 'absolute')
            ->set("rows.{$year->id}.tolerance_value", '1')
            ->assertSet('savedId', $year->id)
            ->assertSee('±1 год');

        $this->assertSame(['absolute', 1.0], [$year->fresh()->tolerance_type, $year->fresh()->tolerance_value]);
        // a reload shows the same
        Livewire::test(SearchSettings::class)->assertSet("rows.{$year->id}.tolerance_value", '1');
    }

    public function test_percent_is_stored_as_a_fraction_without_loss(): void
    {
        $this->signIn();
        $price = $this->row('price');

        foreach (['10' => 0.1, '12.5' => 0.125, '150' => 1.0] as $typed => $stored) {
            Livewire::test(SearchSettings::class)
                ->set("rows.{$price->id}.tolerance_type", 'percentage')
                ->set("rows.{$price->id}.tolerance_value", $typed);

            $this->assertEqualsWithDelta($stored, $price->fresh()->tolerance_value, 1e-9, "typed {$typed}");
        }

        Livewire::test(SearchSettings::class)
            ->set("rows.{$price->id}.tolerance_value", '12.5')
            ->assertSee('±12,5 %');
        Livewire::test(SearchSettings::class)->assertSet("rows.{$price->id}.tolerance_value", '12.5');
    }

    public function test_text_fields_never_get_a_tolerance(): void
    {
        $this->signIn();
        $make = $this->row('make');

        Livewire::test(SearchSettings::class)
            ->set("rows.{$make->id}.tolerance_type", 'absolute')
            ->set("rows.{$make->id}.tolerance_value", '5');

        $this->assertSame(['none', null], [$make->fresh()->tolerance_type, $make->fresh()->tolerance_value]);
    }

    public function test_enabled_and_card_flags_autosave(): void
    {
        $this->signIn();
        $vin = $this->row('mileage');

        Livewire::test(SearchSettings::class)
            ->set("rows.{$vin->id}.enabled", false)
            ->set("rows.{$vin->id}.display_in_card", false);

        $this->assertFalse($vin->fresh()->enabled);
        $this->assertFalse($vin->fresh()->display_in_card);
        $this->assertNotContains('mileage', BotFilterSetting::getCardFields());
    }

    public function test_reset_restores_the_defaults(): void
    {
        $this->signIn();
        $year = $this->row('year');
        $year->update(['tolerance_type' => 'absolute', 'tolerance_value' => 3, 'enabled' => false]);
        $count = BotFilterSetting::count();

        Livewire::test(SearchSettings::class)->callAction('reset');

        $this->assertSame($count, BotFilterSetting::count());
        $fresh = $this->row('year');
        $this->assertTrue($fresh->enabled);
        // BotFilterSettingsSeeder::DEFAULTS: year ±1
        $this->assertSame(['absolute', 1.0], [$fresh->tolerance_type, $fresh->tolerance_value]);
        $this->assertSame(1, BotFilterSetting::where('field_name', 'location')->count());
    }

    public function test_parse_check_uses_the_ai_and_shows_the_tolerant_range(): void
    {
        $this->signIn();
        $this->row('year')->update(['tolerance_type' => 'absolute', 'tolerance_value' => 1]);
        Http::fake(['api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(
            ['make' => 'Hyundai', 'model' => 'Sonata', 'yearFrom' => 2020, 'yearTo' => 2020])]]]])]);

        $c = Livewire::test(SearchSettings::class)->set('testText', 'Соната 2020 года')->call('runTest');

        $result = $c->get('testResult');
        $this->assertSame('AI', $result['mode']);
        $rows = collect($result['rows'])->keyBy('key');
        $this->assertSame('2020', $rows['yearFrom']['exact']);
        $this->assertSame('2019', $rows['yearFrom']['wide']);
        Http::assertSentCount(1);
    }

    public function test_parse_check_falls_back_when_the_ai_fails(): void
    {
        $this->signIn();
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => 'down'], 500)]);

        $result = Livewire::test(SearchSettings::class)->set('testText', 'бмв 2020 года')->call('runTest')->get('testResult');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertStringContainsString('без AI', $result['mode']);
    }
}
