<?php

use App\Http\Controllers\Api\BrowserAuthController;
use App\Http\Controllers\Api\FavoritesController;
use App\Http\Controllers\Api\FiltersController;
use App\Http\Controllers\Api\InspectionsController;
use App\Http\Controllers\Api\SearchChatController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SubscriptionsController;
use App\Http\Controllers\Internal\LotsController;
use Illuminate\Support\Facades\Route;

Route::get('/lots/{lotId}/inspection', [InspectionsController::class, 'show']);

// Browser ↔ Telegram linking (no auth required — public endpoints)
Route::post('/auth/browser-init',          [BrowserAuthController::class, 'init']);
Route::get('/auth/browser-status',         [BrowserAuthController::class, 'status']);

Route::middleware('telegram.auth')->group(function () {
    Route::post('/search',                    [SearchController::class,       'search']);
    Route::post('/search-chat',               [SearchChatController::class,   'chat']);
    Route::post('/search-chat/reset',         [SearchChatController::class,   'reset']);
    Route::get('/favorites',                  [FavoritesController::class,    'index']);
    Route::post('/favorites',                 [FavoritesController::class,    'store']);
    Route::delete('/favorites/{id}',          [FavoritesController::class,    'destroy']);
    Route::get('/subscriptions',              [SubscriptionsController::class,'index']);
    Route::post('/subscriptions',             [SubscriptionsController::class,'store']);
    Route::delete('/subscriptions/{id}',      [SubscriptionsController::class,'destroy']);
    Route::post('/subscriptions/{id}/seen',   [SubscriptionsController::class,'markSeen']);
});

// Internal parser API (protected by X-Internal-Token header)
Route::middleware('internal.token')->prefix('internal')->group(function () {
    Route::post('/lots/upsert', [LotsController::class, 'upsert']);
    Route::post('/lots/delist', [LotsController::class, 'delist']);
});

Route::post('/filters/count', [FiltersController::class, 'count']);
Route::get('/filters/trims', [FiltersController::class, 'trims']);
Route::get('/filters/context', [FiltersController::class, 'context']);
Route::get('/filters', [FiltersController::class, 'index']);
