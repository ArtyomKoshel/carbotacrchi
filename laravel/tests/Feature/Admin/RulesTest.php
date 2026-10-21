<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Platform;
use App\Livewire\Platform\Rules;
use App\Livewire\Platform\SkipLog;
use App\Models\FilterSkipLog;
use App\Models\ParseFilter;
use App\Services\ParseFilterRules;
use Database\Factories\FilterSkipLogFactory;
use Database\Factories\ParseFilterFactory;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/** Rule builder behaviour around the contract: names, order, scope, toggling, hits. */
class RulesTest extends AdminTestCase
{
    private function create(array $form): ParseFilter
    {
        Livewire::test(Rules::class, ['source' => 'encar'])
            ->callAction(TestAction::make('create')->table(), data: $form + ['phase' => 'pre', 'action' => 'skip', 'scope' => 'this'])
            ->assertHasNoFormErrors();

        return ParseFilter::latest('id')->firstOrFail();
    }

    public function test_new_rule_gets_a_unique_generated_name_and_goes_last(): void
    {
        $this->signIn();
        ParseFilterFactory::new()->create(['name' => 'mileage_gt_150000', 'priority' => 30]);

        $rule = $this->create(['field' => 'mileage', 'operator' => 'gt', 'value_number' => '150000']);

        $this->assertSame('mileage_gt_150000_2', $rule->name);
        $this->assertSame(40, $rule->priority);
    }

    public function test_drag_order_rewrites_priority(): void
    {
        $this->signIn();
        $a = ParseFilterFactory::new()->create(['priority' => 5]);
        $b = ParseFilterFactory::new()->create(['priority' => 10]);
        $c = ParseFilterFactory::new()->create(['priority' => 20, 'source' => 'encar']);
        $other = ParseFilterFactory::new()->create(['priority' => 1, 'source' => 'kbcha']);

        Livewire::test(Rules::class, ['source' => 'encar'])
            ->call('toggleTableReordering')
            ->call('reorderTable', [(string) $c->id, (string) $a->id, (string) $b->id]);

        // the parser orders by priority ASC, id ASC
        $this->assertSame([$c->id, $a->id, $b->id], ParseFilter::whereIn('id', [$a->id, $b->id, $c->id])->orderBy('priority')->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, $other->fresh()->priority, 'another platform\'s rule is not touched');
    }

    public function test_list_shows_this_platform_and_shared_rules_only(): void
    {
        $this->signIn();
        $shared = ParseFilterFactory::new()->create(['source' => null]);
        $mine = ParseFilterFactory::new()->create(['source' => 'encar']);
        $foreign = ParseFilterFactory::new()->create(['source' => 'kbcha']);

        Livewire::test(Rules::class, ['source' => 'encar'])
            ->assertCanSeeTableRecords([$shared, $mine])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_toggle_and_delete(): void
    {
        $this->signIn();
        $rule = ParseFilterFactory::new()->create(['enabled' => true]);

        Livewire::test(Rules::class, ['source' => 'encar'])
            ->callAction(TestAction::make('toggle')->table($rule));
        $this->assertFalse($rule->fresh()->enabled);

        Livewire::test(Rules::class, ['source' => 'encar'])
            ->callAction(TestAction::make('delete')->table($rule));
        $this->assertNull($rule->fresh());
    }

    public function test_sentence_reads_like_the_rule(): void
    {
        $rules = app(ParseFilterRules::class);

        $nbsp = "\u{00A0}"; // numbers and units are glued with no-break spaces
        $this->assertSame("Не сохранять, если пробег > 200{$nbsp}000{$nbsp}км", $rules->sentence(ParseFilterFactory::new()->make()));
        $this->assertSame(
            'Не сохранять, если тип продажи = прокатная',
            $rules->sentence(ParseFilterFactory::new()->make(['field' => 'sell_type', 'operator' => 'eq', 'value' => '"rental"'])),
        );
        $this->assertStringContainsString('₩', $rules->sentence(ParseFilterFactory::new()->make(['field' => 'price', 'operator' => 'gte', 'value' => '1000000000'])));
    }

    public function test_hits_come_from_the_skip_log_by_rule_name(): void
    {
        FilterSkipLogFactory::new()->count(3)->create(['rule_name' => 'max_mileage', 'skip_count' => 2]);
        FilterSkipLogFactory::new()->create(['rule_name' => 'min_year', 'source' => 'kbcha']);

        $hits = ParseFilterRules::hits('encar');

        $this->assertSame(3, $hits['max_mileage']['lots']);
        $this->assertSame(6, $hits['max_mileage']['hits']);
        $this->assertArrayNotHasKey('min_year', $hits);
    }

    public function test_hits_link_opens_the_skip_log_filtered_by_rule(): void
    {
        $this->signIn();
        $a = FilterSkipLogFactory::new()->create(['rule_name' => 'max_mileage']);
        $b = FilterSkipLogFactory::new()->create(['rule_name' => 'min_year']);

        $this->assertStringContainsString('rule=max_mileage', Platform::getUrl(['source' => 'encar', 'tab' => 'skips', 'rule' => 'max_mileage']));

        Livewire::withQueryParams(['rule' => 'max_mileage'])
            ->test(SkipLog::class, ['source' => 'encar'])
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b]);
    }

    public function test_skip_log_cleanup_removes_only_stale_rows(): void
    {
        $this->signIn();
        $old = FilterSkipLogFactory::new()->create(['last_skipped_at' => now()->subDays(40)]);
        $fresh = FilterSkipLogFactory::new()->create(['last_skipped_at' => now()->subDay()]);

        Livewire::test(SkipLog::class, ['source' => 'encar'])
            ->callAction(TestAction::make('cleanup')->table(), data: ['days' => 30]);

        $this->assertNull(FilterSkipLog::find($old->id));
        $this->assertNotNull(FilterSkipLog::find($fresh->id));
    }
}
