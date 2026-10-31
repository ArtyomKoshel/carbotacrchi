<?php

namespace Tests\Feature\Api;

use App\AuctionProviders\Che168Provider;
use App\Catalog\CatalogLinker;
use App\Models\CalculatorSetting;
use App\Models\EncarOptionCatalog;
use App\Models\LotInspection;
use App\Models\User;
use App\Search\Specifications\ConditionSpec;
use App\Services\OptionEnricher;
use App\Services\SearchQuery;
use App\Services\TelegramBot;
use App\Support\ClientLotCard;
use Database\Factories\BotUserFactory;
use Database\Factories\LotFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            'id' => '41536978', 'year' => 2021, 'price' => 18_900_000, 'mileage' => 45_210,
            'model' => '쏘나타 (DN8)', 'badge' => '2.0', 'trim' => '프리미엄', 'badge_group' => null, 'drive_type' => 'fwd', 'engine_volume' => 2.0, 'owners_count' => 1, 'insurance_count' => 0,
            'has_accident' => false, 'first_reg_date' => '2021-03-02', 'vin' => 'KMHL341CBMA123456', 'plate_number' => '12가3456',
            'lot_url' => 'https://fem.encar.com/cars/detail/41536978', 'image_url' => 'https://ci.encar.com/x.jpg',
            'photo_dir' => 7, 'photo_base_id' => 41536978, 'photo_codes' => '001,002,021', 'photo_ext_upper' => 0, 'photo_urls' => null,
        ]);
        LotInspection::create(['lot_id' => '41536978', 'inspection_date' => '2026-09-10', 'outer_detail' => 'окрас: бампер']);
        app(CatalogLinker::class)->link(['41536978']);

        return '41536978';
    }

    public function test_che168_options_are_named_by_its_dictionary_and_pass_the_encar_enricher(): void
    {
        EncarOptionCatalog::create(['code' => '001', 'name_ru' => 'Люк', 'sort_order' => 1]);
        EncarOptionCatalog::flushCache();
        $lot = (new Che168Provider())->normalize([
            'id' => 'che168:60213627', 'make' => '日产', 'model' => '逍客', 'price' => 126_800, 'mileage' => 8_000,
            'options' => json_encode(['che168:119', 'che168:999']),
            'raw_data' => json_encode(['che168_options' => ['119' => '方向盘加热', '999' => '座椅通风'], 'che168' => ['transfercount' => 1, 'insurance' => '2027-07']]),
        ])->toArray();
        $this->assertSame([['title' => 'Документы', 'rows' => [['Переоформлений', '1'], ['Страховка до', '07.2027']]]], $lot['sourceSpecs']);

        $this->assertSame(['Подогрев руля', '座椅通风'], array_column($lot['options'], 'name_ru'));
        $this->assertSame($lot['options'], app(OptionEnricher::class)->enrichLots([$lot])[0]['options']);
        $this->assertNull((new Che168Provider())->normalize(['id' => 'che168:1', 'options' => null])->toArray()['options']);
    }

    public function test_a_mini_app_request_marks_the_picker_as_seen(): void
    {
        $id = $this->sonata();
        BotUserFactory::new()->create(['id' => 777, 'username' => 'picker', 'first_name' => 'Old', 'last_seen' => now()->subMonths(5)]);

        $this->getJson("/api/lots/{$id}?init_data=".urlencode(self::initData(777)))->assertOk();
        $this->getJson("/api/lots/{$id}?init_data=".urlencode(self::initData(778)))->assertOk();

        $picker = User::find(777);
        $this->assertTrue($picker->last_seen->gt(now()->subMinute()), '«Был» counts mini app use, not only chat messages');
        $this->assertSame(['picker', 'Picker'], [$picker->username, $picker->first_name], 'the name follows Telegram');
        $this->assertNotNull(User::find(778), 'a picker who only opened the mini app is listed too');
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

        $this->assertStringContainsString('HYUNDAI SONATA (DN8) 2.0 PREMIUM', $text);
        $this->assertStringContainsString('₩18 900 000', $text);
        $this->assertStringContainsString('🔶 Пробег: 45 210 км', $text);
        $this->assertStringContainsString('🔶 Двигатель: бензин 2 л', $text);
        $this->assertStringContainsString('🔶 История: без ДТП · смен владельца: 1', $text);
        foreach (['encar', '41536978', 'KMHL341CBMA123456', '12가3456'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $text);
        }
        $this->assertLessThanOrEqual(1024, mb_strlen(ClientLotCard::html(['year' => 2021, 'make' => 'x', 'model' => str_repeat('y', 2000), 'catalog' => null, 'price' => 1, 'mileage' => 1])));
    }

    public function test_no_accident_needs_the_insurance_record(): void
    {
        $clean = $this->sonata();
        // lot 43020329: the record is not open yet, has_accident=0 is the inspection's undamaged frame
        LotFactory::new()->create(['id' => '43020329', 'has_accident' => false, 'insurance_count' => null]);
        LotFactory::new()->create(['id' => '43020330', 'has_accident' => true, 'insurance_count' => null]);
        // lot 41436905: the record closed, the inspection wrote has_accident=0 over 3 claims
        LotFactory::new()->create(['id' => '41436905', 'has_accident' => false, 'insurance_count' => 3]);

        $accident = fn (string $id) => $this->getJson("/api/lots/{$id}?init_data=".urlencode(self::initData(777)))->json('data.lot.hasAccident');
        $this->assertFalse($accident($clean));
        $this->assertNull($accident('43020329'), 'unknown, not «Без ДТП»');
        $this->assertTrue($accident('43020330'), 'an accident the inspection found stands');
        $this->assertTrue($accident('41436905'), 'claims are an accident');

        $found = fn (bool|string $v) => tap(DB::table('lots'), fn ($q) => (new ConditionSpec)->apply($q, SearchQuery::fromArray(['hasAccident' => $v])))
            ->orderBy('id')->pluck('id')->all();
        $this->assertSame([$clean], $found(false));
        $this->assertSame(['41436905', '43020330'], $found(true));
        $this->assertSame(['43020329'], $found('unknown'), '«Нет данных»: fresh lots whose record Encar has not built yet');
        $this->assertSame(['hasAccident' => 'unknown'], array_intersect_key(SearchQuery::fromArray(['hasAccident' => 'unknown'])->toSearchArray(), ['hasAccident' => 1]), 'a subscription keeps the choice');
    }

    public function test_pickers_can_sort_by_mileage_and_year(): void
    {
        foreach (['mileage_asc', 'mileage_desc', 'year_desc', 'year_asc', 'price_asc'] as $sort) {
            $this->assertSame($sort, SearchQuery::fromArray(['sort' => $sort])->sort);
        }
        $this->assertSame('date', SearchQuery::fromArray(['sort' => 'id; drop'])->sort);
    }

    /** The picker's landed-cost lines go into the card as plain text, after the price. */
    public function test_the_client_card_can_carry_the_calculation(): void
    {
        $id = $this->sonata();
        $calc = ['🧾 Расчёт стоимости:', "Таможня — 4 100 $\u{0007}", '', '', 'Итого: 24 477 $', ...array_fill(0, 20, 'x')];
        $text = $this->postJson("/api/lots/{$id}/share", ['mode' => 'text', 'calc' => $calc, 'init_data' => self::initData(777)])->json('data.text');

        $this->assertStringContainsString("🧾 Расчёт стоимости:\nТаможня — 4 100 $\n\nИтого: 24 477 $", $text, 'one blank line kept');
        $this->assertLessThan(strpos($text, '🧾'), strpos($text, '₩18 900 000'), 'after the price');
        $this->assertSame(7, substr_count($text, "\nx"), 'at most 12 lines');

        $plain = $this->postJson("/api/lots/{$id}/share", ['mode' => 'text', 'calc' => 'not a list', 'init_data' => self::initData(777)])->json('data.text');
        $this->assertStringNotContainsString('Расчёт', $plain);
    }

    /**
     * One Bithumb rate for everyone, the NBRB rates and duty tables for customs, the team's
     * defaults; the last rates when a source is down.
     */
    public function test_the_calculator_gives_the_rates_and_the_team_defaults(): void
    {
        Cache::flush();
        CalculatorSetting::create(['key' => 'delivery_usd', 'value' => '5500']);
        Http::fake([
            'api.bithumb.com/*' => Http::sequence()
                ->push(['status' => '0000', 'data' => ['closing_price' => '1400']])
                ->push('down', 503),
            'api.nbrb.by/exrates/rates/USD*' => Http::sequence()
                ->push(['Cur_Scale' => 1, 'Cur_OfficialRate' => 2.9458, 'Date' => '2026-10-29T00:00:00'])
                ->push('down', 503),
            'api.nbrb.by/exrates/rates/EUR*' => Http::response(['Cur_Scale' => 1, 'Cur_OfficialRate' => 3.2771, 'Date' => '2026-10-29T00:00:00']),
        ]);
        $url = '/api/calculator?' . http_build_query(['init_data' => self::initData(777)]);

        $data = $this->getJson($url)->assertOk()->json('data');
        $this->assertSame([1400, false], [(int) $data['rate']['rate'], $data['rate']['stale']]);
        $this->assertEquals([2.9458, 3.2771, '2026-10-29', false], array_values($data['nbrb']));
        $this->assertEquals([5500, 120, 1282], [$data['defaults']['delivery_usd'], $data['defaults']['customs_fee_byn'], $data['defaults']['recycling_fee_byn']]);
        $this->assertEquals([null, 3.6], end($data['customs']['mid']), 'over 3000 cm³, 3 to 5 years: 3.6 € per cm³');

        Cache::forget('calculator:bithumb');
        Cache::forget('calculator:nbrb');
        $again = $this->getJson($url)->json('data');
        $this->assertSame([1400, true], [(int) $again['rate']['rate'], $again['rate']['stale']]);
        $this->assertSame([2.9458, true], [$again['nbrb']['usd'], $again['nbrb']['stale']]);
    }
}
