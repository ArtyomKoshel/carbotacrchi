<?php

namespace App\Services\Catalog;

use App\Support\Taxonomy\TaxonomyCatalog;
use Illuminate\Support\Facades\DB;

class CatalogLookupService
{
    /** @var array<string, list<array{id:int,model_kr:string,make_kr:string,make_en:string}>> */
    private array $modelsCache = [];

    /** @var array<int, list<array>> */
    private array $gradesCache = [];

    /** @var array<int, list<string>>|null  model_id → [codes] */
    private ?array $generationCodesCache = null;

    /** @var array<string, true>|null  uppercase code → true, for O(1) global lookup */
    private ?array $generationCodesFlat = null;

    /** @var array<string, array>|null  token_type → [token => mapped_value|true] */
    private ?array $tokenMapsCache = null;

    /** @var array<string, true>|null  lowercase spec token → true, built once from tokenMaps */
    private ?array $specTokenSet = null;


    public function lookup(string $makeEn, string $modelRaw): ?CatalogLookupResult
    {
        $modelRaw = trim($modelRaw);
        if ($modelRaw === '' || $makeEn === '') {
            return null;
        }

        // Normalize: lots.make may contain Korean/alias values (미니, 링컨, KG모빌리티(쌍용))
        // catalog_models.make_en stores canonical English (MINI, Lincoln, KG Mobility).
        // Try exact match first, then lowercase match, then pass through as-is.
        $makeNormMap = TaxonomyCatalog::normalizationMap('make');
        $makeEn = $makeNormMap[$makeEn] ?? $makeNormMap[mb_strtolower($makeEn)] ?? $makeEn;

        $models = $this->modelsForMake($makeEn);
        if (empty($models)) {
            return null;
        }

        // Find longest matching model_kr in modelRaw (already sorted desc by length)
        $matchedModel = null;
        $modelOffset = 0;
        foreach ($models as $m) {
            $pos = mb_strpos($modelRaw, $m['model_kr']);
            if ($pos !== false) {
                $matchedModel = $m;
                $modelOffset = $pos;
                break;
            }
        }

        if ($matchedModel === null) {
            return null;
        }

        $afterModel = mb_substr($modelRaw, $modelOffset + mb_strlen($matchedModel['model_kr']));
        $afterModel = trim($afterModel);

        $grades = $this->gradesForModel((int) $matchedModel['id']);
        if (empty($grades)) {
            return null;
        }

        // Find longest matching grade_kr in afterModel (already sorted desc by length)
        $matchedGrade = null;
        $gradeOffset = 0;
        foreach ($grades as $g) {
            $pos = mb_strpos($afterModel, $g['grade_kr']);
            if ($pos !== false) {
                $matchedGrade = $g;
                $gradeOffset = $pos;
                break;
            }
        }

        if ($matchedGrade === null) {
            return null;
        }

        // Text between model_kr and grade_kr is the candidate generation token
        $beforeGrade = trim(mb_substr($afterModel, 0, $gradeOffset));
        $generation  = $this->detectGenerationToken($beforeGrade, (int) $matchedModel['id']);

        $gradeKr = $matchedGrade['grade_kr'];

        return new CatalogLookupResult(
            makeKr: $matchedModel['make_kr'],
            makeEn: $matchedModel['make_en'],
            modelKr: $matchedModel['model_kr'],
            gradeKr: $gradeKr,
            trimKr: $this->extractTrimKr($gradeKr),
            generation: $generation,
            fuelType: $matchedGrade['fuel_type'],
            driveType: $matchedGrade['drive_type'],
            engineVolume: $matchedGrade['engine_volume'] !== null ? (float) $matchedGrade['engine_volume'] : null,
            seatCount: $matchedGrade['seat_count'] !== null ? (int) $matchedGrade['seat_count'] : null,
            cylinders: $matchedGrade['cylinders'] !== null ? (int) $matchedGrade['cylinders'] : null,
            bodyHint: $matchedGrade['body_hint'],
        );
    }

    /** @return list<array> sorted by model_kr length desc */
    private function modelsForMake(string $makeEn): array
    {
        $key = mb_strtolower($makeEn);
        if (isset($this->modelsCache[$key])) {
            return $this->modelsCache[$key];
        }

        $rows = DB::table('catalog_models')
            ->where('make_en', $makeEn)
            ->get(['id', 'model_kr', 'make_kr', 'make_en'])
            ->toArray();

        $rows = array_map(fn ($r) => (array) $r, $rows);
        usort($rows, fn ($a, $b) => mb_strlen($b['model_kr']) <=> mb_strlen($a['model_kr']));

        $this->modelsCache[$key] = $rows;
        return $rows;
    }

    /** @return list<array> sorted by grade_kr length desc */
    private function gradesForModel(int $modelId): array
    {
        if (isset($this->gradesCache[$modelId])) {
            return $this->gradesCache[$modelId];
        }

        $rows = DB::table('catalog_grades')
            ->where('model_id', $modelId)
            ->get(['id', 'grade_kr', 'fuel_type', 'drive_type', 'engine_volume', 'seat_count', 'cylinders', 'body_hint'])
            ->toArray();

        $rows = array_map(fn ($r) => (array) $r, $rows);
        usort($rows, fn ($a, $b) => mb_strlen($b['grade_kr']) <=> mb_strlen($a['grade_kr']));

        $this->gradesCache[$modelId] = $rows;
        return $rows;
    }

    /**
     * Returns a flat set of all known generation codes loaded from the DB.
     * Used by NormalizeEncarTaxonomy to check tokens without model context.
     *
     * @return array<string, true>  uppercase code → true
     */
    public function allGenerationCodes(): array
    {
        $this->ensureGenerationCodesLoaded();
        return $this->generationCodesFlat;
    }

    private function detectGenerationToken(string $gap, int $modelId): ?string
    {
        if ($gap === '') {
            return null;
        }

        $this->ensureGenerationCodesLoaded();
        $modelCodes = $this->generationCodesCache[$modelId] ?? [];

        $tokens = preg_split('/\s+/u', trim($gap)) ?: [];
        foreach ($tokens as $tok) {
            $upper = strtoupper($tok);
            // Model-specific codes first (highest precision)
            if (in_array($upper, $modelCodes, true)) {
                return $upper;
            }
            // Alphanumeric chassis codes: DN8, CN7, NX4, F30, E46
            if (preg_match('/^[A-Z]{1,3}\d{1,3}[A-Z]?$/i', $tok)) {
                return $upper;
            }
            // Korean generation label: 1세대, 3세대
            if (preg_match('/^\d+세대$/u', $tok)) {
                return $tok;
            }
            // Any code in the global set (2-letter codes like LF, AD, HG …)
            if (isset($this->generationCodesFlat[$upper])) {
                return $upper;
            }
        }

        return null;
    }

    /**
     * Returns all token maps loaded from catalog_token_maps.
     * Structure: ['fuel' => ['디젤' => 'diesel', ...], 'drive' => [...], 'body' => [...],
     *             'gen_non_chassis' => ['ev' => true, ...], 'gen_exclude' => [...],
     *             'engine_family' => [...], 'variant_exclude' => [...]]
     *
     * @return array<string, array<string, string|true>>
     */
    public function tokenMaps(): array
    {
        if ($this->tokenMapsCache !== null) {
            return $this->tokenMapsCache;
        }

        $this->tokenMapsCache = [];
        $rows = DB::table('catalog_token_maps')
            ->get(['token', 'token_type', 'mapped_value'])
            ->toArray();

        foreach ($rows as $r) {
            $this->tokenMapsCache[$r->token_type][$r->token] =
                $r->mapped_value !== null ? $r->mapped_value : true;
        }

        return $this->tokenMapsCache;
    }

    /**
     * Strip spec tokens (fuel, drive, engine family, volume, cylinders, seat count)
     * from a raw grade_kr string, returning only the trim-meaningful portion.
     *
     * Examples:
     *   "VX 2WD"                   → "VX"
     *   "디젤 RX 2WD"              → "RX"
     *   "2.2 디젤 2WD 프레스티지"  → "프레스티지"
     *   "헤리티지"                  → "헤리티지"
     *   "Advantage"                 → "Advantage"
     *   "2WD"                       → null  (nothing left after stripping)
     */
    private function extractTrimKr(string $gradeKr): ?string
    {
        // Build spec token set once per service lifetime
        if ($this->specTokenSet === null) {
            $maps = $this->tokenMaps();
            $this->specTokenSet = [];
            // All spec token types from catalog — nothing hardcoded here.
            foreach ([
                'fuel', 'drive', 'body', 'engine_family', 'cylinder_config',
                'grade_spec_code', 'grade_engine_vol', 'grade_gen_label', 'grade_seat',
            ] as $type) {
                foreach (array_keys($maps[$type] ?? []) as $token) {
                    $this->specTokenSet[mb_strtolower((string) $token)] = true;
                }
            }
        }

        $tokens = preg_split('/\s+/u', trim($gradeKr)) ?: [];
        $keep   = [];

        foreach ($tokens as $tok) {
            if (isset($this->specTokenSet[mb_strtolower($tok)])) {
                continue;
            }
            $keep[] = $tok;
        }

        $result = trim(implode(' ', $keep));
        return $result !== '' ? $result : null;
    }

    private function ensureGenerationCodesLoaded(): void
    {
        if ($this->generationCodesCache !== null) {
            return;
        }

        $this->generationCodesCache = [];
        $this->generationCodesFlat  = [];

        $rows = DB::table('catalog_model_generations')
            ->get(['model_id', 'code'])
            ->toArray();

        foreach ($rows as $r) {
            $code = strtoupper($r->code);
            $this->generationCodesCache[(int) $r->model_id][] = $code;
            $this->generationCodesFlat[$code]                  = true;
        }
    }
}
