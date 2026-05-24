<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Taxonomy\TaxonomyNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminLotsController extends Controller
{
    public function browse(Request $request)
    {
        $q = DB::table('lots');

        // Text search
        if ($search = trim((string) $request->input('search', ''))) {
            $q->where(function ($sub) use ($search) {
                $sub->where('id', 'like', "%{$search}%")
                    ->orWhere('plate_number', 'like', "%{$search}%")
                    ->orWhere('vin', 'like', "%{$search}%")
                    ->orWhere('make', 'like', "%{$search}%")
                    ->orWhereRaw('model LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('model_en LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('generation LIKE ?', ["%{$search}%"]);
            });
        }

        // Status
        $status = $request->input('status', 'all');
        if ($status === 'active')   $q->where('is_active', true);
        if ($status === 'inactive') $q->where('is_active', false);

        // Source
        if ($source = $request->input('source')) {
            $q->where('source', $source);
        }

        // Make
        if ($make = trim((string) $request->input('make', ''))) {
            $variants = TaxonomyNormalizer::expandToDbValues('make', $make);
            $q->where(function ($sub) use ($variants, $make) {
                if ($variants !== []) {
                    $sub->whereIn('make', $variants)
                        ->orWhereRaw('make LIKE ?', [$make . '%']);
                    return;
                }

                $sub->whereRaw('make LIKE ?', [$make . '%']);
            });
        }

        // Model
        if ($model = trim((string) $request->input('model', ''))) {
            $q->where(function ($sub) use ($model) {
                $sub->where('model', $model)->orWhere('model_en', $model);
            });
        }
        if ($generation = trim((string) $request->input('generation', ''))) {
            $q->whereRaw('generation LIKE ?', ["%{$generation}%"]);
        }
        if ($trim = trim((string) $request->input('trim', ''))) {
            $q->whereRaw('`trim` LIKE ?', ["%{$trim}%"]);
        }

        // Year
        if ($yf = (int) $request->input('year_from')) $q->where('year', '>=', $yf);
        if ($yt = (int) $request->input('year_to'))   $q->where('year', '<=', $yt);

        // Price (KRW)
        if ($pmin = (int) $request->input('price_min')) $q->where('price', '>=', $pmin);
        if ($pmax = (int) $request->input('price_max')) $q->where('price', '<=', $pmax);

        // Mileage
        if ($mmn = (int) $request->input('mileage_min')) $q->where('mileage', '>=', $mmn);
        if ($mmx = (int) $request->input('mileage_max')) $q->where('mileage', '<=', $mmx);

        // Engine volume
        if ($emin = (float) $request->input('engine_min')) $q->where('engine_volume', '>=', $emin);
        if ($emax = (float) $request->input('engine_max')) $q->where('engine_volume', '<=', $emax);

        // Date ranges
        if ($listedFrom = trim((string) $request->input('listed_from', ''))) {
            $q->whereDate('listed_at', '>=', $listedFrom);
        }
        if ($listedTo = trim((string) $request->input('listed_to', ''))) {
            $q->whereDate('listed_at', '<=', $listedTo);
        }
        if ($firstRegFrom = trim((string) $request->input('first_reg_from', ''))) {
            $q->whereDate('first_reg_date', '>=', $firstRegFrom);
        }
        if ($firstRegTo = trim((string) $request->input('first_reg_to', ''))) {
            $q->whereDate('first_reg_date', '<=', $firstRegTo);
        }

        // Owners count
        if (($oc = $request->input('owners_count')) !== null && $oc !== '') {
            $q->where('owners_count', '<=', (int) $oc);
        }

        // Insurance count
        if (($ic = $request->input('insurance_max')) !== null && $ic !== '') {
            $q->where('insurance_count', '<=', (int) $ic);
        }

        // Booleans
        $accident = $request->input('has_accident');
        if ($accident !== null && $accident !== '') {
            $q->where('has_accident', (bool)(int)$accident);
        }
        $flood = $request->input('flood_history');
        if ($flood !== null && $flood !== '') {
            $q->where('flood_history', (bool)(int)$flood);
        }

        // Multi-selects
        if ($bt = $request->input('body_types')) {
            $vals = TaxonomyNormalizer::expandManyToDbValues('body_type', (array)$bt);
            if ($vals !== []) $q->whereIn('body_type', $vals);
        }
        if ($tr = $request->input('transmissions')) {
            $vals = TaxonomyNormalizer::expandManyToDbValues('transmission', (array)$tr);
            if ($vals !== []) $q->whereIn('transmission', $vals);
        }
        if ($fu = $request->input('fuels')) {
            $vals = TaxonomyNormalizer::expandManyToDbValues('fuel', (array)$fu);
            if ($vals !== []) $q->whereIn('fuel', $vals);
        }
        if ($dr = $request->input('drive_types')) {
            $vals = TaxonomyNormalizer::expandManyToDbValues('drive_type', (array)$dr);
            if ($vals !== []) $q->whereIn('drive_type', $vals);
        }
        if ($co = $request->input('colors'))          $q->whereIn('color', (array)$co);

        // Sort
        $sort = $request->input('sort', 'newest');
        match ($sort) {
            'price_asc'    => $q->orderBy('price', 'asc'),
            'price_desc'   => $q->orderBy('price', 'desc'),
            'mileage_asc'  => $q->orderBy('mileage', 'asc'),
            'mileage_desc' => $q->orderBy('mileage', 'desc'),
            'year_asc'     => $q->orderBy('year', 'asc'),
            'year_desc'    => $q->orderBy('year', 'desc'),
            'oldest'       => $q->orderBy('listed_at', 'asc')->orderBy('id', 'asc'),
            default        => $q->orderByDesc('listed_at')->orderByDesc('id'),
        };

        $perPage = min(200, max(20, (int) $request->input('per_page', 50)));
        $lots    = $q->paginate($perPage)->withQueryString();

        // Filter options from DB
        $rawPairs = DB::table('lots')
            ->whereNotNull('make')->where('make', '!=', '')
            ->whereNotNull('model')->where('model', '!=', '')
            ->select(['make', 'model'])
            ->distinct()->orderBy('make')->orderBy('model')
            ->get();
        $makesModels = [];
        foreach ($rawPairs as $row) {
            $mk = TaxonomyNormalizer::normalize('make', (string) $row->make);
            $md = trim((string) $row->model);
            if ($mk === '' || $md === '') continue;
            $makesModels[$mk][] = $md;
        }
        ksort($makesModels);
        foreach ($makesModels as &$mdList) {
            $mdList = array_values(array_unique($mdList));
        }
        unset($mdList);
        $generations = DB::table('lots')->whereNotNull('generation')->where('generation', '!=', '')->distinct()->orderBy('generation')->pluck('generation')->filter()->values();
        $bodyTypes = collect(TaxonomyNormalizer::normalizeMany('body_type', DB::table('lots')->distinct()->pluck('body_type')->filter()->values()->toArray()))->sort()->values();
        $transList = collect(TaxonomyNormalizer::normalizeMany('transmission', DB::table('lots')->distinct()->pluck('transmission')->filter()->values()->toArray()))->sort()->values();
        $fuelList = collect(TaxonomyNormalizer::normalizeMany('fuel', DB::table('lots')->distinct()->pluck('fuel')->filter()->values()->toArray()))->sort()->values();
        $driveList = collect(TaxonomyNormalizer::normalizeMany('drive_type', DB::table('lots')->distinct()->pluck('drive_type')->filter()->values()->toArray()))->sort()->values();
        $colorList  = DB::table('lots')->distinct()->orderBy('color')->pluck('color')->filter()->values();
        $sources    = DB::table('lots')->distinct()->pluck('source')->values();

        return view('admin.lots-browse', compact(
            'lots',
            'makesModels',
            'generations',
            'bodyTypes',
            'transList',
            'fuelList',
            'driveList',
            'colorList',
            'sources'
        ));
    }
}
