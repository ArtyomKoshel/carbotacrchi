<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalculatorSetting;
use App\Services\Calculator\BithumbRate;
use Illuminate\Http\JsonResponse;

class CalculatorController extends Controller
{
    /**
     * GET /api/calculator — what the mini app's calculator starts from: the Bithumb rate
     * (one for everyone, cached) and the team's defaults from /admin/calculator.
     */
    public function show(BithumbRate $rate): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => [
            'rate'     => $rate->current(),
            'defaults' => CalculatorSetting::values(),
            'company'  => (string) config('calculator.company'),
        ]]);
    }
}
