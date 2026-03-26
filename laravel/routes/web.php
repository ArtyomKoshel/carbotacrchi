<?php

use App\Http\Controllers\Bot\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/bot/webhook', [WebhookController::class, 'handle']);
