<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Bot\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/bot/webhook', [WebhookController::class, 'handle']);

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login',  [AdminController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminController::class, 'processLogin'])->name('login.submit');
    Route::post('/logout',[AdminController::class, 'logout'])->name('logout');
});

Route::middleware('admin.auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/',                              [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/changes',                       [AdminController::class, 'changes'])->name('changes');
    Route::get('/stats',                         [AdminController::class, 'stats'])->name('stats');
    Route::get('/logs',                          [AdminController::class, 'logs'])->name('logs');
    Route::get('/lots',                          [AdminController::class, 'lots'])->name('lots');
    Route::post('/lots/{lotId}/reparse',         [AdminController::class, 'reparseLot'])->name('lots.reparse');
    Route::get('/reparse/{id}/status',           [AdminController::class, 'reparseStatus'])->name('reparse.status');
    Route::get('/jobs',                          [AdminController::class, 'jobs'])->name('jobs');
    Route::post('/jobs',                         [AdminController::class, 'launchJob'])->name('jobs.launch');
    Route::post('/jobs/{id}/cancel',             [AdminController::class, 'cancelJob'])->name('jobs.cancel');
    Route::get('/jobs/{id}/progress',            [AdminController::class, 'jobProgress'])->name('jobs.progress');
    Route::get('/jobs/{id}/events',             [AdminController::class, 'jobEvents'])->name('jobs.events');
    Route::get('/jobs/{id}/detail',              [AdminController::class, 'jobDetail'])->name('jobs.detail');
    Route::get('/jobs/{id}/log',                 [AdminController::class, 'jobLog'])->name('jobs.log');
    Route::get('/schedules',                       [AdminController::class, 'schedules'])->name('schedules');
    Route::get('/proxy-balance',                 [AdminController::class, 'proxyBalance'])->name('proxy.balance');
    Route::post('/schedules/{source}',           [AdminController::class, 'updateSchedule'])->name('schedules.update');
    Route::get('/filters',                       [AdminController::class, 'filters'])->name('filters');
    Route::post('/filters',                      [AdminController::class, 'createFilter'])->name('filters.create');
    Route::put('/filters/{id}',                  [AdminController::class, 'updateFilter'])->name('filters.update');
    Route::delete('/filters/{id}',               [AdminController::class, 'deleteFilter'])->name('filters.delete');
    Route::patch('/filters/{id}/toggle',         [AdminController::class, 'toggleFilter'])->name('filters.toggle');

    // Fields: unified page — registry + source mappings + coverage stats.
    // Replaces legacy `/admin/accuracy` and `/admin/field-mappings`.
    Route::get('/fields',                        [AdminController::class, 'fields'])->name('fields');
    Route::post('/fields/recompute',             [AdminController::class, 'fieldsRecompute'])->name('fields.recompute');
    Route::get('/fields-schema.json',            [AdminController::class, 'fieldsSchema'])->name('fields.schema');

    // Legacy redirects — keep old bookmarks working.
    Route::get('/accuracy',       fn () => redirect()->route('admin.fields'))->name('accuracy');
    Route::get('/field-mappings', fn () => redirect()->route('admin.fields'))->name('field.mappings');
});

Route::middleware('admin.auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/logs/download', [AdminController::class, 'logsDownload'])->name('logs.download');
    Route::post('/logs/clear',   [AdminController::class, 'logsClear'])->name('logs.clear');
});

Route::get('/up', fn() => response('OK'));
