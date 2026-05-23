<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
                    ->orWhereRaw('model_en LIKE ?', ["%{$search}%"]);
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
            $q->whereRaw('make LIKE ?', [$make . '%']);
        }

        // Model
        if ($model = trim((string) $request->input('model', ''))) {
            $q->whereRaw('model LIKE ? OR model_en LIKE ?', ["%{$model}%", "%{$model}%"]);
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
        if ($bt = $request->input('body_types'))      $q->whereIn('body_type', (array)$bt);
        if ($tr = $request->input('transmissions'))   $q->whereIn('transmission', (array)$tr);
        if ($fu = $request->input('fuels'))           $q->whereIn('fuel', (array)$fu);
        if ($dr = $request->input('drive_types'))     $q->whereIn('drive_type', (array)$dr);
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
            'oldest'       => $q->orderBy('parsed_at', 'asc'),
            default        => $q->orderByDesc('parsed_at'),
        };

        $perPage = min(200, max(20, (int) $request->input('per_page', 50)));
        $lots    = $q->paginate($perPage)->withQueryString();

        // Filter options from DB (distinct values, cached implicitly by MySQL query cache)
        $makes      = DB::table('lots')->distinct()->orderBy('make')->pluck('make')->filter()->values();
        $bodyTypes  = DB::table('lots')->distinct()->orderBy('body_type')->pluck('body_type')->filter()->values();
        $transList  = DB::table('lots')->distinct()->orderBy('transmission')->pluck('transmission')->filter()->values();
        $fuelList   = DB::table('lots')->distinct()->orderBy('fuel')->pluck('fuel')->filter()->values();
        $driveList  = DB::table('lots')->distinct()->orderBy('drive_type')->pluck('drive_type')->filter()->values();
        $colorList  = DB::table('lots')->distinct()->orderBy('color')->pluck('color')->filter()->values();
        $sources    = DB::table('lots')->distinct()->pluck('source')->values();

        return view('admin.lots-browse', compact(
            'lots', 'makes', 'bodyTypes', 'transList', 'fuelList', 'driveList', 'colorList', 'sources'
        ));
    }
}
