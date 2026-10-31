<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The reference catalog for the filters (app/Catalog): which makes it covers, and a make's
 * models → generations → modifications and complectations with their active lots. The
 * miniapp cascades on the client from one make's `cells` — every (model, generation,
 * modification, complectation, platform) that has lots, with how many. A null is "not stated": those
 * lots are counted too and the miniapp offers them as «… не указано», so no lot drops out.
 */
class CatalogController extends Controller
{
    private const TTL = 600;

    /** The cache key of a make's tree (v2: cells carry the platform). */
    public static function makeKey(int $makeId): string
    {
        return "api_catalog_make_v2_{$makeId}";
    }

    /** GET /api/catalog — the makes with active lots on the catalog. */
    public function makes(): JsonResponse
    {
        // which makes, by the variants (their active lots as of the last catalog:remap): no scan of lots
        $makes = Cache::remember('api_catalog_makes', self::TTL, fn () => DB::table('ref_variants as v')
            ->join('ref_makes as m', 'm.id', '=', 'v.make_id')
            ->whereNotNull('v.model_id')
            ->groupBy('m.id', 'm.name')->orderBy('m.name')
            ->selectRaw('m.id, m.name, SUM(v.lots) AS lots')->get()
            ->map(fn ($m) => ['id' => (int) $m->id, 'name' => $m->name, 'lots' => (int) $m->lots])->all());

        return response()->json(['ok' => true, 'data' => ['makes' => $makes]]);
    }

    /** GET /api/catalog/{makeId} — the make's tree. */
    public function make(int $makeId): JsonResponse
    {
        $data = Cache::remember(self::makeKey($makeId), self::TTL, function () use ($makeId) {
            // per platform too: with one platform picked the miniapp counts only its lots
            $cells = DB::table('lots')->where('is_active', true)->where('ref_make_id', $makeId)
                ->groupBy('ref_model_id', 'ref_generation_id', 'ref_modification_id', 'ref_complectation_id', 'source')
                ->selectRaw('ref_model_id m, ref_generation_id g, ref_modification_id d, ref_complectation_id c, COUNT(*) n, source s')
                ->get();

            $ids = fn (string $k) => $cells->pluck($k)->filter()->unique()->values()->all();
            $models = DB::table('ref_models')->whereIn('id', $ids('m'))->orderBy('name')->get(['id', 'name', 'body', 'fuel']);
            $generations = DB::table('ref_generations')->whereIn('id', $ids('g'))->orderBy('year_from')
                ->get(['id', 'model_id', 'code', 'number', 'year_from', 'year_to', 'fuel']);
            $modifications = DB::table('ref_modifications')->whereIn('id', $ids('d'))->orderBy('code')
                ->get(['id', 'generation_id', 'code', 'fuel', 'drive']);
            $complectations = DB::table('ref_complectations')->whereIn('id', $ids('c'))->orderBy('name')->get(['id', 'name']);

            return [
                'make' => DB::table('ref_makes')->where('id', $makeId)->first(['id', 'name']),
                'models' => $models,
                'generations' => $generations,
                'modifications' => $modifications,
                'complectations' => $complectations,
                'cells' => $cells->map(fn ($c) => [$c->m === null ? null : (int) $c->m, $c->g === null ? null : (int) $c->g,
                    $c->d === null ? null : (int) $c->d, $c->c === null ? null : (int) $c->c, (int) $c->n, $c->s])->all(),
            ];
        });

        if ($data['make'] === null) {
            return response()->json(['ok' => false, 'error' => 'Unknown make'], 404);
        }
        return response()->json(['ok' => true, 'data' => $data]);
    }
}
