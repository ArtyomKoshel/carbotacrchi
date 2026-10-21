<?php

namespace Tests\Feature\Admin;

use App\Livewire\Platform\Rules;
use App\Models\Lot;
use App\Models\ParseFilter;
use App\Services\ParseFilterRules;
use Database\Factories\LotFactory;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The rule builder must save exactly what the Python parser reads, and the
 * «сколько лотов попадёт» preview must agree with how the parser matches.
 * Both sides read contracts/fixtures/parse_filters.json; the
 * parser side is parser/tests/test_rule_contract.py.
 */
class ParseRuleContractTest extends AdminTestCase
{
    private const FIXTURE = __DIR__ . '/../../../../contracts/fixtures/parse_filters.json';

    private const ROW_KEYS = ['source', 'field', 'operator', 'value', 'action', 'phase', 'rule_group_id'];

    public static function cases(): array
    {
        $cases = json_decode(file_get_contents(self::FIXTURE), true)['cases'];

        return collect($cases)->mapWithKeys(fn (array $c) => [$c['case'] => [$c]])->all();
    }

    public static function samples(): array
    {
        $out = [];
        foreach (json_decode(file_get_contents(self::FIXTURE), true)['cases'] as $c) {
            foreach ($c['lots'] as $i => $sample) {
                $out["{$c['case']}[{$i}]"] = [$c, $sample];
            }
        }

        return $out;
    }

    #[DataProvider('cases')]
    public function test_builder_saves_the_row_the_parser_reads(array $case): void
    {
        $this->signIn();
        $form = $case['form'] + ['rule_group_id' => null, 'description' => null];

        Livewire::test(Rules::class, ['source' => 'encar'])
            ->callAction(TestAction::make('create')->table(), data: $form)
            ->assertHasNoFormErrors();

        $saved = ParseFilter::latest('id')->firstOrFail();
        foreach (self::ROW_KEYS as $key) {
            $this->assertSame($case['row'][$key], $saved->getRawOriginal($key) === null ? null : (string) $saved->getRawOriginal($key), "{$case['case']}: {$key}");
        }
        $this->assertTrue($saved->enabled);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $saved->name);
    }

    #[DataProvider('cases')]
    public function test_editing_without_changes_keeps_the_row(array $case): void
    {
        $this->signIn();
        $rule = ParseFilter::create($case['row'] + ['name' => $case['case'], 'priority' => 10, 'enabled' => true]);

        Livewire::test(Rules::class, ['source' => 'encar'])
            ->callAction(TestAction::make('edit')->table($rule))
            ->assertHasNoFormErrors();

        $rule->refresh();
        foreach (self::ROW_KEYS as $key) {
            $this->assertSame($case['row'][$key], $rule->getRawOriginal($key) === null ? null : (string) $rule->getRawOriginal($key), "{$case['case']}: {$key}");
        }
        $this->assertSame($case['case'], $rule->name, 'the name is the skip-log key and never changes');
    }

    #[DataProvider('samples')]
    public function test_preview_count_agrees_with_the_parser(array $case, array $sample): void
    {
        $rules = app(ParseFilterRules::class);
        $row = $case['row'];
        $value = $row['value'] === null ? null : json_decode($row['value'], true);

        if (($case['sql'] ?? true) === false) {
            $this->assertNull($rules->previewCount($row['field'], $row['operator'], $value, 'encar'));

            return;
        }

        LotFactory::new()->create($sample['lot']);
        LotFactory::new()->inactive()->create($sample['lot']); // inactive lots never count
        $this->assertSame(2, Lot::count());

        $this->assertSame(
            $sample['matches'] ? 1 : 0,
            $rules->previewCount($row['field'], $row['operator'], $value, 'encar'),
            "{$case['case']}: " . json_encode($sample['lot'], JSON_UNESCAPED_UNICODE),
        );
    }
}
