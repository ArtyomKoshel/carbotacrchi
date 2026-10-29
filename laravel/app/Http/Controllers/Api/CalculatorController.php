<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalculatorSetting;
use App\Services\Calculator\BithumbRate;
use App\Services\Calculator\NbrbRates;
use Illuminate\Http\JsonResponse;

class CalculatorController extends Controller
{
    /**
     * GET /api/calculator — what the mini app's calculator starts from: the Bithumb rate
     * (one for everyone, cached) and the team's defaults from /admin/calculator.
     */
    public function show(BithumbRate $rate, NbrbRates $nbrb): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => [
            'rate'     => $rate->current(),
            'nbrb'     => $nbrb->current(),
            'customs'  => config('calculator.customs'),
            'defaults' => CalculatorSetting::values(),
            'company'  => (string) config('calculator.company'),
        ]]);
    }
}
