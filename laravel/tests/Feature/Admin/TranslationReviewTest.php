<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\TranslationReview;
use App\Models\Lot;
use App\Models\Translation;
use App\Services\TranslationCatalog;
use Database\Factories\LotFactory;
use Database\Factories\TranslationFactory;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The KR→EN review queue. translate:run only fills empty *_en columns, so a
 * corrected or rejected translation must reach the lots that already took it.
 */
class TranslationReviewTest extends AdminTestCase
{
    /** Lots around one trim translation 한국 → Old; only the first two may change. */
    private function lotsAround(): array
    {
        return [
            'took_it'   => LotFactory::new()->create(['trim' => '한국', 'trim_en' => 'Old']),
            'empty'     => LotFactory::new()->create(['trim' => '한국', 'trim_en' => null]),
            'custom'    => LotFactory::new()->create(['trim' => '한국', 'trim_en' => 'Hand made']),
            'other_kr'  => LotFactory::new()->create(['trim' => '다른', 'trim_en' => 'Old']),
            'other_cat' => LotFactory::new()->create(['badge' => '한국', 'badge_en' => 'Old', 'trim' => '다른2', 'trim_en' => 'X']),
        ];
    }

    public function test_accept_marks_the_translation_reviewed(): void
    {
        $this->signIn();
        $t = TranslationFactory::new()->create(['category' => 'make', 'kr' => '가', 'en' => 'Ga']);

        Livewire::test(TranslationReview::class)->call('acceptCurrent')->assertNotified();

        $this->assertSame('manual', $t->fresh()->source);
        $this->assertSame('Ga', $t->fresh()->en);
    }

    public function test_correction_rewrites_only_matching_lots(): void
    {
        $this->signIn();
        $t = TranslationFactory::new()->create(['category' => 'trim', 'kr' => '한국', 'en' => 'Old']);
        $lots = $this->lotsAround();

        Livewire::test(TranslationReview::class)
            ->callAction('edit', data: ['en' => 'New', 'ru' => null])
            ->assertHasNoActionErrors();

        $this->assertSame(['New', 'manual'], [$t->fresh()->en, $t->fresh()->source]);
        $this->assertSame('New', $lots['took_it']->fresh()->trim_en);
        $this->assertSame('New', $lots['empty']->fresh()->trim_en);
        $this->assertSame('Hand made', $lots['custom']->fresh()->trim_en, 'a value from elsewhere is kept');
        $this->assertSame('Old', $lots['other_kr']->fresh()->trim_en, 'another Korean value is kept');
        $this->assertSame('Old', $lots['other_cat']->fresh()->badge_en, 'another category is kept');
    }

    public function test_correction_reaches_more_lots_than_one_chunk(): void
    {
        $t = TranslationFactory::new()->create(['category' => 'model', 'kr' => '모델', 'en' => 'Old']);
        $base = LotFactory::new()->raw();
        $rows = [];
        for ($i = 0; $i < 1203; $i++) {
            $rows[] = ['id' => (string) (60000000 + $i), 'model' => '모델', 'model_en' => 'Old', 'raw_data' => '{}', 'vin' => null, 'plate_number' => null]
                + array_diff_key($base, array_flip(['id', 'raw_data', 'vin', 'plate_number']));
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('lots')->insert($chunk);
        }

        $n = app(TranslationCatalog::class)->update($t, 'New');

        $this->assertSame(1203, $n);
        $this->assertSame(1203, Lot::where('model_en', 'New')->count());
    }

    public function test_reject_clears_the_lots_so_translate_run_retranslates(): void
    {
        $this->signIn();
        $t = TranslationFactory::new()->create(['category' => 'trim', 'kr' => '한국', 'en' => 'Old']);
        $lots = $this->lotsAround();

        Livewire::test(TranslationReview::class)->callAction('reject');

        $this->assertNull(Translation::find($t->id));
        $this->assertNull($lots['took_it']->fresh()->trim_en);
        $this->assertSame('Hand made', $lots['custom']->fresh()->trim_en);
        $this->assertSame('Old', $lots['other_kr']->fresh()->trim_en);
    }

    public function test_rejecting_a_color_keeps_the_lots(): void
    {
        $t = TranslationFactory::new()->create(['category' => 'color', 'kr' => '흰색', 'en' => 'white']);
        $lot = LotFactory::new()->create(['color' => 'white']);

        $this->assertSame(0, app(TranslationCatalog::class)->reject($t));
        $this->assertSame('white', $lot->fresh()->color);
    }

    public function test_untranslated_lists_korean_values_without_a_dictionary_row(): void
    {
        $this->signIn();
        TranslationFactory::new()->create(['category' => 'trim', 'kr' => '있음', 'en' => 'Has']);
        LotFactory::new()->count(2)->create(['trim' => '없음', 'trim_en' => null]);
        LotFactory::new()->create(['trim' => '있음', 'trim_en' => null]);
        LotFactory::new()->create(['trim' => '번역됨', 'trim_en' => 'Done elsewhere']);

        $rows = app(TranslationCatalog::class)->untranslated('trim');

        $this->assertSame([['kr' => '없음', 'n' => 2]], $rows->map(fn ($r) => ['kr' => $r->kr, 'n' => (int) $r->n])->all());

        Livewire::withQueryParams(['status' => 'missing', 'category' => 'trim'])
            ->test(TranslationReview::class)
            ->assertSee('없음')
            ->callAction('edit', data: ['en' => 'Without', 'ru' => null]);

        $this->assertSame('manual', Translation::where('kr', '없음')->sole()->source);
        $this->assertSame(2, Lot::where('trim_en', 'Without')->count());
    }

    public function test_accept_page_accepts_exactly_the_page_shown(): void
    {
        $this->signIn();
        Translation::where('source', 'ai')->delete(); // migrations may seed some
        TranslationFactory::new()->count(30)->create();
        $manual = Translation::where('source', '!=', 'ai')->count();

        Livewire::test(TranslationReview::class)->callAction('acceptPage');

        $this->assertSame($manual + 25, Translation::where('source', '!=', 'ai')->count());
        $this->assertSame(5, Translation::where('source', 'ai')->count());
    }

    public function test_keyboard_navigation_moves_the_cursor(): void
    {
        $this->signIn();
        TranslationFactory::new()->count(3)->create();

        Livewire::test(TranslationReview::class)
            ->assertSet('index', 0)
            ->call('next')->assertSet('index', 1)
            ->call('next')->call('next')->assertSet('index', 2)
            ->call('previous')->assertSet('index', 1)
            ->assertSeeHtml("case 'j'")
            ->assertSeeHtml("case 'd'");
    }
}
