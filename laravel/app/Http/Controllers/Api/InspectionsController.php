<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LotInspection;
use Illuminate\Http\JsonResponse;

class InspectionsController extends Controller
{
    public function show(string $lotId): JsonResponse
    {
        $insp = LotInspection::where('lot_id', $lotId)->first();

        if (!$insp) {
            return response()->json(null, 404);
        }

        return response()->json([
            'ok'   => true,
            'data' => $insp->toArray(),
        ]);
    }
}
