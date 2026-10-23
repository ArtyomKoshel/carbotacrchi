<?php

namespace Tests\Feature\Api;

use App\Models\LotInspection;
use App\Services\SearchQuery;
use App\Services\TelegramBot;
use App\Support\ClientLotCard;
use Database\Factories\LotFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** A lot by its own link, and the card a picker sends to a client. */
final class LotApiTest extends TestCase
{
    use RefreshesDatabaseOnce;

    private const TOKEN = '123456:test-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auction.bot_token' => self::TOKEN]);
        $this->app->instance(TelegramBot::class, new TelegramBot(self::TOKEN, 'https://api.telegram.test/bot'));
    }

    /** initData as Telegram signs it for this user (core.telegram.org/bots/webapps#validating-data). */
    private static function initData(int $userId): string
    {
        $params = ['auth_date' => (string) time(), 'query_id' => 'AAH', 'user' => json_encode(['id' => $userId, 'first_name' => 'Picker'])];
        ksort($params);
        $check = implode("\n", array_map(fn ($k, $v) => "{$k}={$v}", array_keys($params), $params));
        $secret = hash_hmac('sha256', self::TOKEN, 'WebAppData', true);

        return http_build_query($params + ['hash' => hash_hmac('sha256', $check, $secret)]);
    }

    private function sonata(): string
    {
        LotFactory::new()->create([
            'id' => '41536978', 'year' => 2021, 'price' => 18_900_000, 'mileage' => 45_210, 'model_en' => 'Sonata (DN8)',
            'trim_en' => 'Premium', 'drive_type' => 'fwd', 'engine_volume' => 2.0, 'owners_count' => 1, 'insurance_count' => 0,
            'has_accident' => false, 'first_reg_date' => '2021-03-02', 'vin' => 'KMHL341CBMA123456', 'plate_number' => '12가3456',
            'lot_url' => 'https://fem.encar.com/cars/detail/41536978', 'image_url' => 'https://ci.encar.com/x.jpg',
            'photo_dir' => 7, 'photo_base_id' => 41536978, 'photo_codes' => '001,002,021', 'photo_ext_upper' => 0, 'photo_urls' => null,
        ]);
        LotInspection::create(['lot_id' => '41536978', 'inspection_date' => '2026-09-10', 'outer_detail' => 'окрас: бампер']);

        return '41536978';
    }

    public function test_a_lot_has_its_own_link_with_the_whole_gallery(): void
    {
        $id = $this->sonata();

        $res = $this->getJson("/api/lots/{$id}?init_data=".urlencode(self::initData(777)))->assertOk()->json('data');

        $this->assertSame($id, $res['lot']['id']);
        $this->assertSame(3, $res['lot']['photoCount']);
        $this->assertStringStartsWith('https://ci.encar.com/carpicture07/pic4153/41536978_001.jpg', $res['lot']['photos'][0]);
        $this->assertStringContainsString('_021.jpg', $res['lot']['photos'][2]);
        $this->assertSame('окрас: бампер', $res['inspection']['outer_detail']);
        $this->getJson('/api/lots/1?init_data='.urlencode(self::initData(777)))->assertNotFound();
    }

    public function test_the_user_is_the_one_telegram_signed_not_the_one_sent(): void
    {
        $id = $this->sonata();
        Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->postJson("/api/lots/{$id}/share", ['mode' => 'chat', 'user_id' => 1, 'init_data' => self::initData(777)])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendMediaGroup') && (int) $r['chat_id'] === 777);
        Http::assertNotSent(fn (HttpRequest $r) => (int) ($r['chat_id'] ?? 0) === 1);
        $this->postJson("/api/lots/{$id}/share", ['mode' => 'chat', 'init_data' => 'user=%7B%22id%22%3A777%7D&hash=forged'])->assertUnauthorized();
    }

    public function test_telegram_share_prepares_a_message_and_falls_back_to_the_pickers_chat(): void
    {
        $id = $this->sonata();
        Http::fakeSequence('api.telegram.test/*')
            ->push(['ok' => true, 'result' => ['id' => 'prep-1', 'expiration_date' => time() + 3600]])
            ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: method is unavailable'])
            ->push(['ok' => true, 'result' => []]);

        $first = $this->postJson("/api/lots/{$id}/share", ['mode' => 'prepare', 'init_data' => self::initData(777)])->json('data');
        $this->assertSame('prep-1', $first['prepared_message_id']);

        $second = $this->postJson("/api/lots/{$id}/share", ['mode' => 'prepare', 'init_data' => self::initData(777)])->json('data');
        $this->assertTrue($second['sent_to_chat'], 'Telegram refused the prepared message: the album goes to the picker');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/savePreparedInlineMessage')
            && str_contains((string) $r['result'], '"photo_url":"https://ci.encar.com/carpicture07/pic4153/41536978_001.jpg'));
    }

    public function test_the_client_card_leads_to_the_picker_not_to_the_listing(): void
    {
        $id = $this->sonata();
        $text = $this->postJson("/api/lots/{$id}/share", ['mode' => 'text', 'init_data' => self::initData(777)])->json('data.text');

        $this->assertStringContainsString('2021 Hyundai Sonata (DN8) Premium', $text);
        $this->assertStringContainsString('₩18 900 000', $text);
        $this->assertStringContainsString('45 210 км · бензин', $text);
        $this->assertStringContainsString('История: без ДТП · владельцев: 1 · страховых случаев: 0', $text);
        $this->assertStringContainsString('Осмотр 10.09.2026: окрас: бампер', $text);
        foreach (['encar', '41536978', 'KMHL341CBMA123456', '12가3456'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $text);
        }
        $this->assertLessThanOrEqual(1024, mb_strlen(ClientLotCard::html(['year' => 2021, 'make' => 'x', 'makeEn' => null, 'model' => str_repeat('y', 2000), 'modelEn' => null, 'modelGroupEn' => null, 'price' => 1, 'mileage' => 1])));
    }

    public function test_pickers_can_sort_by_mileage_and_year(): void
    {
        foreach (['mileage_asc', 'mileage_desc', 'year_desc', 'year_asc', 'price_asc'] as $sort) {
            $this->assertSame($sort, SearchQuery::fromArray(['sort' => $sort])->sort);
        }
        $this->assertSame('date', SearchQuery::fromArray(['sort' => 'id; drop'])->sort);
    }
}
