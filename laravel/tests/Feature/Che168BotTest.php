<?php

namespace Tests\Feature;

use App\Models\BotFilterSetting;
use App\Services\SearchQuery;
use App\Services\TelegramBot;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** The bot and the AI search with Che168 next to Encar. */
final class Che168BotTest extends TestCase
{
    use RefreshesDatabaseOnce;

    public function test_a_search_that_names_no_platform_looks_at_every_one_and_keeps_them_when_saved(): void
    {
        config(['auction.sources' => ['encar', 'che168']]);

        $q = SearchQuery::fromArray(['make' => 'BMW']);
        $this->assertSame(['encar', 'che168'], $q->sources);
        $this->assertSame(['encar', 'che168'], $q->toSearchArray()['sources']);
        $this->assertSame(['che168'], SearchQuery::fromArray(['sources' => ['che168']])->sources);
        // a subscription saved before Che168 came reads as Encar's (subscriptions:check)
        $this->assertSame(['encar'], SearchQuery::fromArray(['sources' => ['encar'], ...['make' => 'BMW']])->sources);
    }

    public function test_a_che168_card_in_the_bot_says_what_the_inspection_found_not_dtp(): void
    {
        BotFilterSetting::query()->update(['display_in_card' => false]);
        foreach (['has_accident', 'owners_count'] as $field) {
            BotFilterSetting::query()->updateOrInsert(['field_name' => $field], ['display_in_card' => true]);
        }
        $text = fn (array $lot) => (new \ReflectionMethod(TelegramBot::class, 'buildLotCardText'))
            ->invoke(new TelegramBot('1:x'), ['id' => 'che168:1', 'source' => 'che168', 'make' => 'BYD', 'model' => 'Seal', 'price' => 100_000, 'currency' => 'CNY', ...$lot]);

        $this->assertStringContainsString('🔍 Осмотр S · 13 замеч.', $text(['inspectionSummary' => ['grade' => 'S', 'problem' => null, 'notes' => 13, 'water' => true]]));
        $this->assertStringContainsString('💧 Следы воды', $text(['inspectionSummary' => ['grade' => 'S', 'problem' => null, 'notes' => 13, 'water' => true]]));
        $this->assertStringContainsString('✅ Осмотр S · без замечаний', $text(['inspectionSummary' => ['grade' => 'S', 'problem' => null, 'notes' => 0, 'water' => false]]));
        $this->assertStringContainsString('🔍 Нет осмотра', $text([]));
        $this->assertStringContainsString('👤 1', $text(['ownersCount' => 1]));
        $this->assertStringNotContainsString('ДТП', $text([]));
    }
}
