<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoritesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->input('user_id', 0);

        $favorites = Favorite::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($f) => array_merge($f->lot_data ?? [], ['_saved_at' => $f->created_at]));

        return response()->json(['ok' => true, 'data' => $favorites]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'  => 'required|integer',
            'lot_id'   => 'required|string',
            'source'   => 'required|string',
            'lot_data' => 'required|array',
        ]);

        Favorite::firstOrCreate(
            ['user_id' => $request->input('user_id'), 'lot_id' => $request->input('lot_id')],
            ['source' => $request->input('source'), 'lot_data' => $request->input('lot_data')]
        );

        return response()->json(['ok' => true, 'data' => ['added' => true]]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        Favorite::where('user_id', $request->input('user_id', 0))
            ->where('lot_id', $id)
            ->delete();

        return response()->json(['ok' => true, 'data' => ['removed' => true]]);
    }
}
