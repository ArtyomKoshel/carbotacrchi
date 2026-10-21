<?php

namespace App\Services;

use App\Models\Translation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The KR→EN dictionary (translations) and the lots columns it fills.
 *
 * translate:run stores AI translations with source='ai' and copies them into
 * empty lots.*_en columns only. So a corrected translation must also be
 * pushed to the lots that already took the old value, and a rejected one
 * cleared from them so translate:run asks again. Reviewed rows get
 * source='manual'.
 */
class TranslationCatalog
{
    /** category => [lots column with the Korean value, lots column with the English one] */
    public const CATEGORIES = [
        'make'        => ['make', 'make_en'],
        'model_group' => ['model_group', 'model_group_en'],
        'model'       => ['model', 'model_en'],
        'badge_group' => ['badge_group', 'badge_group_en'],
        'badge'       => ['badge', 'badge_en'],
        'trim'        => ['trim', 'trim_en'],
        // translate:run overwrites these columns with the English value
        'color'       => ['color', 'color'],
        'seat_color'  => ['seat_color', 'seat_color'],
    ];

    public const LABELS = [
        'make'        => 'Марка',
        'model_group' => 'Модельный ряд',
        'model'       => 'Модель',
        'badge_group' => 'Группа версий',
        'badge'       => 'Версия',
        'trim'        => 'Комплектация',
        'color'       => 'Цвет',
        'seat_color'  => 'Цвет салона',
    ];

    public const SOURCES = [
        'ai'      => 'AI, не проверен',
        'manual'  => 'проверен',
        'catalog' => 'из каталога',
    ];

    private const CHUNK = 500;

    public static function label(?string $category): string
    {
        return self::LABELS[$category] ?? (string) $category;
    }

    /** True when lots keep the Korean value next to the English one (not color). */
    public static function keepsKorean(string $category): bool
    {
        [$kr, $en] = self::CATEGORIES[$category] ?? [null, null];

        return $kr !== null && $kr !== $en;
    }

    /** Lots (any status) with this Korean value. Index on make/model/model_group/badge; a scan otherwise. */
    public function usage(string $category, string $kr): int
    {
        [$krCol] = self::CATEGORIES[$category] ?? [null];

        return $krCol ? DB::table('lots')->where($krCol, $kr)->count() : 0;
    }

    /**
     * Usage of several values of one category in one query.
     *
     * @param  list<string>  $values
     * @return array<string, int>
     */
    public function usageMany(string $category, array $values): array
    {
        [$krCol] = self::CATEGORIES[$category] ?? [null];
        if (!$krCol || $values === []) {
            return [];
        }

        return DB::table('lots')->whereIn($krCol, $values)->groupBy($krCol)
            ->selectRaw("`{$krCol}` AS v, COUNT(*) AS n")->pluck('n', 'v')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** A few active lots with this value, for context. */
    public function examples(string $category, string $kr, int $limit = 4): Collection
    {
        [$krCol] = self::CATEGORIES[$category] ?? [null];
        if (!$krCol) {
            return collect();
        }

        return DB::table('lots')->where($krCol, $kr)->where('is_active', true)->limit($limit)
            ->get(['id', 'source', 'make', 'make_en', 'model_group_en', 'model_en', 'model', 'badge', 'trim', 'year', 'price']);
    }

    /** Lots that would change if the English value moved from $oldEn. */
    public function affected(Translation $t, ?string $oldEn = null): int
    {
        return $this->targetIds($t->category, $t->kr, $oldEn ?? $t->en)->count();
    }

    /** Mark an AI translation as checked. */
    public function accept(Translation $t): void
    {
        $t->update(['source' => 'manual']);
    }

    /**
     * Correct a translation and the lots that carry its old English value
     * (or none yet).
     *
     * @return int lots updated
     */
    public function update(Translation $t, string $en, ?string $ru = null): int
    {
        $old = $t->en;
        $t->update(['en' => $en, 'ru' => $ru, 'source' => 'manual']);

        return $this->writeLots($t->category, $t->kr, $old, $en);
    }

    /**
     * Remove a wrong translation. Lots that took it get an empty English value
     * again, so the next translate:run translates them anew (color columns
     * hold no Korean, so they keep the value).
     *
     * @return int lots cleared
     */
    public function reject(Translation $t): int
    {
        $cleared = self::keepsKorean($t->category) ? $this->writeLots($t->category, $t->kr, $t->en, null) : 0;
        $t->delete();

        return $cleared;
    }

    /** @return array{0: Translation, 1: int} new manual translation and lots filled */
    public function create(string $category, string $kr, string $en, ?string $ru = null): array
    {
        $t = Translation::updateOrCreate(['category' => $category, 'kr' => $kr], ['en' => $en, 'ru' => $ru, 'source' => 'manual']);

        return [$t, $this->writeLots($category, $kr, null, $en)];
    }

    /**
     * Korean values in lots that have no translation, most used first.
     *
     * @return Collection<int, object{kr: string, n: int}>
     */
    public function untranslated(string $category, int $limit = 200): Collection
    {
        [$krCol, $enCol] = self::CATEGORIES[$category];

        return DB::table('lots as l')
            ->leftJoin('translations as t', fn ($j) => $j->on('t.kr', '=', "l.{$krCol}")->where('t.category', $category))
            ->whereNotNull("l.{$krCol}")->where("l.{$krCol}", '!=', '')
            ->whereNull('t.id')
            ->when(self::keepsKorean($category),
                fn ($q) => $q->where(fn ($w) => $w->whereNull("l.{$enCol}")->orWhere("l.{$enCol}", '')),
                // color columns already hold English once translated: only Hangul values are open
                fn ($q) => $q->whereRaw("l.`{$krCol}` REGEXP '[가-힣]'"))
            ->groupBy("l.{$krCol}")
            ->selectRaw("l.`{$krCol}` AS kr, COUNT(*) AS n")
            ->orderByDesc('n')
            ->limit($limit)
            ->get();
    }

    /**
     * Ids of lots with this Korean value whose English column is $currentEn or
     * empty. Read first, then updated by primary key in chunks, so an unindexed
     * column is scanned once without holding row locks.
     */
    private function targetIds(string $category, string $kr, ?string $currentEn): Collection
    {
        [$krCol, $enCol] = self::CATEGORIES[$category] ?? [null, null];
        if (!$krCol) {
            return collect();
        }

        return DB::table('lots')
            ->when(self::keepsKorean($category),
                fn ($q) => $q->where($krCol, $kr)->where(fn ($w) => $w->whereNull($enCol)->orWhere($enCol, '')
                    ->when($currentEn !== null && $currentEn !== '', fn ($w) => $w->orWhere($enCol, $currentEn))),
                fn ($q) => $q->where($enCol, $currentEn ?? $kr))
            ->pluck('id');
    }

    private function writeLots(string $category, string $kr, ?string $oldEn, ?string $newEn): int
    {
        [, $enCol] = self::CATEGORIES[$category] ?? [null, null];
        if (!$enCol || ($newEn === null && !self::keepsKorean($category))) {
            return 0;
        }
        $n = 0;
        foreach ($this->targetIds($category, $kr, $oldEn)->chunk(self::CHUNK) as $ids) {
            $n += DB::table('lots')->whereIn('id', $ids->all())->update([$enCol => $newEn, 'updated_at' => now()]);
        }

        return $n;
    }
}
