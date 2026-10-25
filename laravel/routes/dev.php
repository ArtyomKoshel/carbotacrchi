<?php

use App\Http\Controllers\DevLoginController;
use Illuminate\Support\Facades\Route;

/*
 * Local sign-in without Telegram or a password — for working locally and for the UI tests (e2e/).
 * Loaded by bootstrap/app.php only when APP_ENV=local: these routes do not exist anywhere else.
 */

Route::prefix('api')->middleware('api')->group(function () {
    Route::post('/auth/dev-login', [DevLoginController::class, 'miniapp']);
});

Route::middleware('web')->group(function () {
    Route::get('/dev/admin-login', [DevLoginController::class, 'admin']);
});
