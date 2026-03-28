<?php

use App\Http\Controllers\Api\FavoritesController;
use App\Http\Controllers\Api\FiltersController;
use App\Http\Controllers\Api\InspectionsController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SubscriptionsController;
use Illuminate\Support\Facades\Route;

Route::get('/lots/{lotId}/inspection', [InspectionsController::class, 'show']);

Route::middleware('telegram.auth')->group(function () {
    Route::post('/search',                    [SearchController::class,       'search']);
    Route::get('/favorites',                  [FavoritesController::class,    'index']);
    Route::post('/favorites',                 [FavoritesController::class,    'store']);
    Route::delete('/favorites/{id}',          [FavoritesController::class,    'destroy']);
    Route::get('/subscriptions',              [SubscriptionsController::class,'index']);
    Route::post('/subscriptions',             [SubscriptionsController::class,'store']);
    Route::delete('/subscriptions/{id}',      [SubscriptionsController::class,'destroy']);
    Route::post('/subscriptions/{id}/seen',   [SubscriptionsController::class,'markSeen']);
});

Route::get('/filters', [FiltersController::class, 'index']);
