<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Extracts structured fields from a raw Encar grade string.
 *
 * Algorithm (positive matching):
 *   1. catalog_models       → model
 *   2. catalog_grades       → fuel / drive / engine_vol / seat / cylinders / body  (gold data)
 *   3. catalog_model_generations + pattern → generation
 *   4. catalog_trims        → trim (positive match, longest phrase wins)
 *   5. catalog_token_maps   → remaining spec tokens stripped  (fuel/drive fallback + strip noise)
 *   6. remainder            → TaxonomyAnomalyQueue
 *
 * The service works on the combined raw string (model + badge_kr as stored in raw_data).
 * All lookups are cached after first load — safe to reuse across lots in one artisan run.
 */
class GradeExtractorService
{
    // ── Caches (populated lazily) ──────────────────────────────────────────

    /** makeEn → list of [id, model_kr, make_kr, make_en] sorted by model_kr length desc */
    private array $modelsByMake = [];

    /** modelId → list of uppercase chassis codes */
    private array $gensByModel = [];

    /** flat uppercase code → true, for quick global lookups */
    private ?array $allGenCodes = null;

    /** makeEn ('' = universal) → list of [trim_kr, trim_en, priority]
     *  sorted by priority desc then trim_kr length desc */
    private array $trimsByMake = [];

    /** token_type → [lowercase_token => mapped_value|null] */
    private ?array $tokenMaps = null;

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Extract all known fields from $raw.
     *
     * @param string $raw     Combined raw string, e.g. "5시리즈 (G60) 523d xDrive M 스포츠"
     * @param string $makeEn  Canonical English make, e.g. "BMW"
     */
    public function extract(string $raw, string $makeEn): GradeExtractionResult
    {
        $result = new GradeExtractionResult();

        $s = $this->preClean($raw);
        if ($s === '') {
            return $result;
        }

        // We progressively remove matched spans from $working.
        // At the end, whatever is left is the remainder.
        $working = $s;

        // ── 1. Find model ────────────────────────────────────────────────
        $matchedModelId = null;
        foreach ($this->modelsForMake($makeEn) as $m) {
            $pos = mb_strpos($working, $m['model_kr']);
            if ($pos !== false) {
                $result->model  = $m['model_kr'];
                $matchedModelId = (int) $m['id'];
                $working        = $this->cutSpan($working, $pos, mb_strlen($m['model_kr']));
                break;
            }
        }

        // ── 2. Generation detection ──────────────────────────────────────
        if ($matchedModelId !== null && $result->generation === null) {
            [$gen, $working] = $this->detectAndStripGeneration($working, $matchedModelId);
            $result->generation = $gen;
        }

        // ── 3. Trim (catalog_trims positive match) ───────────────────────
        if ($result->trim === null) {
            $trimHit = $this->matchTrimInText($working, $makeEn);
            if ($trimHit !== null) {
                $result->trim   = $trimHit['trim_kr'];
                $result->trimEn = $trimHit['trim_en'];
                // Remove matched trim phrase from working string
                $pos = mb_stripos($working, $trimHit['trim_kr']);
                if ($pos !== false) {
                    $working = $this->cutSpan($working, $pos, mb_strlen($trimHit['trim_kr']));
                }
            }
        }

        // ── 4. Token maps: fuel / drive / engine / body / strip spec tokens ─
        $working = $this->processTokenMaps($working, $result);

        // ── 5. Remainder ──────────────────────────────────────────────────
        $result->remainder = $this->buildRemainder($working);

        return $result;
    }

    // ── Catalog loaders ────────────────────────────────────────────────────

    /**
     * @return list<array{id:int, model_kr:string, make_kr:string, make_en:string}>
     */
    private function modelsForMake(string $makeEn): array
    {
        $key = mb_strtolower($makeEn);
        if (!isset($this->modelsByMake[$key])) {
            $rows = DB::table('catalog_models')
                ->where('make_en', $makeEn)
                ->get(['id', 'model_kr', 'make_kr', 'make_en'])
                ->map(fn ($r) => (array) $r)
                ->toArray();
            usort($rows, fn ($a, $b) => mb_strlen($b['model_kr']) <=> mb_strlen($a['model_kr']));
            $this->modelsByMake[$key] = $rows;
        }
        return $this->modelsByMake[$key];
    }

    /**
     * Returns trims sorted by: priority desc, then trim_kr length desc.
     * Includes universal trims (make_en='') + brand-specific (make_en=$makeEn).
     *
     * @return list<array{trim_kr:string, trim_en:?string, priority:int}>
     */
    private function trimsForMake(string $makeEn): array
    {
        $key = mb_strtolower($makeEn);
        if (!isset($this->trimsByMake[$key])) {
            $rows = DB::table('catalog_trims')
                ->where(fn ($q) => $q->where('make_en', '')->orWhere('make_en', $makeEn))
                ->get(['trim_kr', 'trim_en', 'priority'])
                ->map(fn ($r) => (array) $r)
                ->toArray();
            usort($rows, function ($a, $b) {
                if ($b['priority'] !== $a['priority']) {
                    return $b['priority'] <=> $a['priority'];
                }
                return mb_strlen($b['trim_kr']) <=> mb_strlen($a['trim_kr']);
            });
            $this->trimsByMake[$key] = $rows;
        }
        return $this->trimsByMake[$key];
    }

    private function tokenMaps(): array
    {
        if ($this->tokenMaps === null) {
            $this->tokenMaps = [];
            DB::table('catalog_token_maps')
                ->get(['token', 'token_type', 'mapped_value'])
                ->each(function ($r) {
                    $this->tokenMaps[$r->token_type][$r->token] =
                        $r->mapped_value !== null ? $r->mapped_value : true;
                });
        }
        return $this->tokenMaps;
    }

    private function ensureGenCodesLoaded(): void
    {
        if ($this->allGenCodes !== null) {
            return;
        }
        $this->allGenCodes  = [];
        $this->gensByModel  = [];

        DB::table('catalog_model_generations')
            ->get(['model_id', 'code'])
            ->each(function ($r) {
                $code = strtoupper($r->code);
                $this->gensByModel[(int) $r->model_id][] = $code;
                $this->allGenCodes[$code] = true;
            });
    }

    // ── Core matching helpers ──────────────────────────────────────────────

    /**
     * Find the longest matching trim phrase in $text.
     * Brand-specific trims (higher priority) are tried before universal ones.
     */
    private function matchTrimInText(string $text, string $makeEn): ?array
    {
        if ($text === '') {
            return null;
        }
        foreach ($this->trimsForMake($makeEn) as $t) {
            if (mb_stripos($text, $t['trim_kr']) !== false) {
                return $t;
            }
        }
        return null;
    }

    /**
     * Detect generation code in $text and return [code|null, $textWithCodeRemoved].
     */
    private function detectAndStripGeneration(string $text, int $modelId): array
    {
        $this->ensureGenCodesLoaded();

        $modelCodes = $this->gensByModel[$modelId] ?? [];
        $tokens     = preg_split('/[\s()]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $tok) {
            $upper = strtoupper($tok);

            // Model-specific codes have highest precision
            if (in_array($upper, $modelCodes, true)) {
                return [$upper, $this->stripToken($text, $tok)];
            }
            // Classic chassis pattern: DN8, G60, LF, F30, E46 (1-3 alpha + 1-3 digits)
            if (preg_match('/^[A-Z]{1,3}\d{1,3}[A-Z]?$/i', $tok)) {
                return [$upper, $this->stripToken($text, $tok)];
            }
            // Korean generation label: 1세대, 2세대 …
            if (preg_match('/^\d+세대$/u', $tok)) {
                return [$tok, $this->stripToken($text, $tok)];
            }
            // Global code set (2-letter codes like LF, AD, HG)
            if (isset($this->allGenCodes[$upper])) {
                return [$upper, $this->stripToken($text, $tok)];
            }
        }

        return [null, $text];
    }

    /**
     * Walk token_maps: strip spec tokens, fill in fuel/drive/engine fallbacks.
     */
    private function processTokenMaps(string $text, GradeExtractionResult $result): string
    {
        $maps = $this->tokenMaps();

        $fuelMap      = $maps['fuel']              ?? [];
        $driveMap     = $maps['drive']             ?? [];
        $bodyMap      = $maps['body']              ?? [];
        $specCodeMap  = $maps['grade_spec_code']   ?? [];
        $engineVolMap = $maps['grade_engine_vol']  ?? [];
        $engFamMap    = $maps['engine_family']      ?? [];
        $cylMap       = $maps['cylinder_config']    ?? [];
        $genLabelMap  = $maps['grade_gen_label']    ?? [];
        $seatMap      = $maps['grade_seat']         ?? [];
        $prefixMap    = $maps['model_prefix']       ?? [];

        $tokens  = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keep    = [];

        foreach ($tokens as $rawTok) {
            $tok = mb_strtolower($rawTok);

            // Skip bare parenthetical tokens "(foo)"
            if (preg_match('/^\([^)]*\)$/u', $rawTok)) {
                continue;
            }

            // Normalise paren-suffixed tokens before map lookups.
            // Korean grade strings often attach engine-tech annotations directly to the fuel
            // or drive word without a space: "디젤(e-VGT)", "가솔린(GDI)", "2WD(e-AWD)".
            // Stripping the trailing (...) lets the bare word hit its map entry.
            $tn = ($tok !== '' && str_contains($tok, '('))
                ? (string) preg_replace('/\s*\([^)]*\)$/u', '', $tok)
                : $tok;   // $tn = token-normalised (paren suffix stripped)

            // ── Fuel ────────────────────────────────────────────────────────
            $fuelVal = $fuelMap[$tn] ?? $fuelMap[$tok] ?? null;
            if ($fuelVal !== null && $fuelVal !== true) {
                if ($result->fuel === null) {
                    $result->fuel = $fuelVal;
                }
                continue; // always strip fuel tokens even when fuel already known
            }
            // ── Drive ────────────────────────────────────────────────────────
            $driveVal = $driveMap[$tn] ?? $driveMap[$tok] ?? null;
            if ($driveVal !== null && $driveVal !== true) {
                if ($result->driveType === null) {
                    $result->driveType = $driveVal;
                }
                continue; // always strip drive tokens
            }
            // ── Body ─────────────────────────────────────────────────────────
            $bodyVal = $bodyMap[$tn] ?? $bodyMap[$tok] ?? null;
            if ($bodyVal !== null && $bodyVal !== true) {
                if ($result->bodyType === null) {
                    $result->bodyType = $bodyVal;
                }
                continue; // always strip body tokens
            }
            // ── Engine volume (e.g. "2.0t", "2.2d") ─────────────────────────
            if (isset($engineVolMap[$tn]) || isset($engineVolMap[$tok])) {
                if ($result->engineVolume === null && preg_match('/^(\d+\.\d+)/u', $rawTok, $m)) {
                    $result->engineVolume = (float) $m[1];
                }
                continue;
            }
            // ── Cylinder config (e.g. "v8", "w12") ───────────────────────────
            if (isset($cylMap[$tn]) || isset($cylMap[$tok])) {
                $cylTok = isset($cylMap[$tn]) ? $tn : $tok;
                if ($result->cylinders === null && preg_match('/^[a-z](\d+)$/u', $cylTok, $m)) {
                    $result->cylinders = (int) $m[1];
                }
                continue;
            }
            // ── Bare decimal engine volume (e.g. "2.0") ───────────────────────
            if (preg_match('/^(\d+\.\d+)$/u', $rawTok, $m)) {
                $v = (float) $m[1];
                if ($v >= 0.5 && $v <= 10.0) {
                    if ($result->engineVolume === null) {
                        $result->engineVolume = $v;
                    }
                    continue;
                }
            }
            // ── Seat count "7인승" ─────────────────────────────────────────────
            if (preg_match('/^(\d{1,2})인승$/u', $rawTok, $m)) {
                $result->seatCount ??= (int) $m[1];
                continue;
            }
            // ── Strip-only maps (spec codes, engine family, gen labels, prefixes) ─
            // Check both normalised and original form.
            if (isset($specCodeMap[$tn])  || isset($specCodeMap[$tok])
                || isset($engFamMap[$tn]) || isset($engFamMap[$tok])
                || isset($genLabelMap[$tn]) || isset($genLabelMap[$tok])
                || isset($prefixMap[$tn])  || isset($prefixMap[$tok])) {
                continue;
            }

            $keep[] = $rawTok;
        }

        return implode(' ', $keep);
    }

    // ── String helpers ─────────────────────────────────────────────────────

    private function preClean(string $s): string
    {
        // Strip business/purpose annotations: (렌터카), (장애인용), (수출형), etc.
        $s = preg_replace('/\s*\([^)]*(?:렌터카|장애인용|특장업체|수출형|캠핑카|영업용|택시형)[^)]*\)\s*/u', ' ', $s) ?? $s;
        return trim((string) preg_replace('/\s{2,}/u', ' ', $s));
    }

    /**
     * Remove a span of $length multibyte characters starting at $pos from $s.
     * Replaces with a single space to preserve token separation.
     */
    private function cutSpan(string $s, int $pos, int $length): string
    {
        $before = mb_substr($s, 0, $pos);
        $after  = mb_substr($s, $pos + $length);
        return (string) preg_replace('/\s{2,}/u', ' ', trim($before . ' ' . $after));
    }

    /**
     * Remove the first occurrence of $token (case-sensitive, whole token) from $s.
     */
    private function stripToken(string $s, string $token): string
    {
        // Match token surrounded by spaces / parens / start / end
        $pattern = '/(?<![^\s(])' . preg_quote($token, '/') . '(?![^\s)])/u';
        $result  = preg_replace($pattern, ' ', $s, 1);
        // Clean up orphaned empty parens left after token removal: "엣지( )" → "엣지"
        $result  = preg_replace('/\(\s*\)/u', '', $result ?? $s);
        return trim((string) preg_replace('/\s{2,}/u', ' ', $result ?? $s));
    }

    /**
     * Build remainder: trim, collapse spaces, drop lone punctuation/parens.
     */
    private function buildRemainder(string $s): string
    {
        // Drop isolated single parens, dashes, dots left over from extraction
        $s = preg_replace('/(?<!\S)[().,\-\/]+(?!\S)/u', ' ', $s) ?? $s;
        $s = trim((string) preg_replace('/\s{2,}/u', ' ', $s));
        return $s;
    }
}
