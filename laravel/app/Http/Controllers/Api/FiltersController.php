<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class FiltersController extends Controller
{
    public function index(): JsonResponse
    {
        $makesFile = storage_path('app/data/makes_models.json');
        $makes = file_exists($makesFile)
            ? json_decode(file_get_contents($makesFile), true) ?? []
            : [];

        $currentYear = (int) date('Y');

        return response()->json([
            'ok'   => true,
            'data' => [
                'makes'   => $makes,
                'sources' => [
                    ['key' => 'copart',  'name' => 'Copart'],
                    ['key' => 'iai',     'name' => 'IAAI'],
                    ['key' => 'manheim', 'name' => 'Manheim'],
                    ['key' => 'encar',   'name' => 'Encar'],
                    ['key' => 'kbcha',   'name' => 'KBChacha'],
                ],
                'years'         => range($currentYear, 2000),
                'damageTypes'   => [
                    'FRONT END','REAR END','SIDE','ALL OVER','ROLLOVER',
                    'WATER/FLOOD','HAIL','ELECTRICAL','MECHANICAL',
                    'MINOR DENTS','VANDALISM','BURN','UNDERCARRIAGE',
                ],
                'titleTypes'    => ['Clean','Salvage','Rebuilt','Flood','Lemon','Junk','Non-repairable'],
                'bodyTypes'     => ['Sedan','SUV','Truck','Coupe','Hatchback','Wagon','Van','Convertible','Crossover'],
                'transmissions' => ['Automatic','Manual','CVT'],
                'fuelTypes'     => ['Gasoline','Diesel','Hybrid','Electric'],
                'driveTypes'    => ['FWD','RWD','AWD','4WD'],
            ],
        ]);
    }
}
