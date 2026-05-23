<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminLotsController;
use App\Http\Controllers\Admin\AdminUsersController;
use App\Http\Controllers\Admin\BotFiltersController;
use App\Http\Controllers\Admin\FieldsController;
use App\Http\Controllers\Admin\LogsController;
use App\Http\Controllers\Admin\ParseFiltersController;
use App\Http\Controllers\Admin\ParserJobsController;
use App\Http\Controllers\Admin\TaxonomyRulesController;
use App\Http\Controllers\Bot\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/bot/webhook', [WebhookController::class, 'handle'])
    ->middleware(['throttle:120,1', 'telegram.webhook']);

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login',  [AdminController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminController::class, 'processLogin'])->name('login.submit');
    Route::post('/logout',[AdminController::class, 'logout'])->name('logout');
});

Route::middleware('admin.auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/',                              [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/changes',                       [AdminController::class, 'changes'])->name('changes');
    Route::get('/stats',                         [AdminController::class, 'stats'])->name('stats');
    Route::get('/logs',                          [LogsController::class, 'logs'])->name('logs');
    Route::get('/lots',                          [ParserJobsController::class, 'lots'])->name('lots');
    Route::post('/lots/{lotId}/reparse',         [ParserJobsController::class, 'reparseLot'])->name('lots.reparse');
    Route::get('/reparse/{id}/status',           [ParserJobsController::class, 'reparseStatus'])->name('reparse.status');
    Route::get('/jobs',                          [ParserJobsController::class, 'jobs'])->name('jobs');
    Route::post('/jobs',                         [ParserJobsController::class, 'launchJob'])->name('jobs.launch');
    Route::post('/jobs/{id}/cancel',             [ParserJobsController::class, 'cancelJob'])->name('jobs.cancel');
    Route::get('/jobs/{id}/progress',            [ParserJobsController::class, 'jobProgress'])->name('jobs.progress');
    Route::get('/jobs/{id}/events',              [ParserJobsController::class, 'jobEvents'])->name('jobs.events');
    Route::get('/jobs/{id}/detail',              [ParserJobsController::class, 'jobDetail'])->name('jobs.detail');
    Route::get('/jobs/{id}/log',                 [ParserJobsController::class, 'jobLog'])->name('jobs.log');
    Route::get('/schedules',                     [ParserJobsController::class, 'schedules'])->name('schedules');
    Route::get('/proxy-balance',                 [ParserJobsController::class, 'proxyBalance'])->name('proxy.balance');
    Route::post('/schedules/{source}',           [ParserJobsController::class, 'updateSchedule'])->name('schedules.update');
    Route::get('/filters',                       [ParseFiltersController::class, 'index'])->name('filters');
    Route::post('/filters',                      [ParseFiltersController::class, 'create'])->name('filters.create');
    Route::put('/filters/{id}',                  [ParseFiltersController::class, 'update'])->name('filters.update');
    Route::delete('/filters/{id}',               [ParseFiltersController::class, 'delete'])->name('filters.delete');
    Route::patch('/filters/{id}/toggle',         [ParseFiltersController::class, 'toggle'])->name('filters.toggle');
    Route::get('/bot-filters',                   [BotFiltersController::class, 'index'])->name('bot-filters');
    Route::post('/bot-filters',                  [BotFiltersController::class, 'update'])->name('bot-filters.update');
    Route::post('/bot-filters/reset',            [BotFiltersController::class, 'reset'])->name('bot-filters.reset');
    Route::post('/bot-filters/preview',          [BotFiltersController::class, 'preview'])->name('bot-filters.preview');

    // Filter Skip Log
    Route::get('/filter-skip-log',              [App\Http\Controllers\Admin\FilterSkipLogController::class, 'index'])->name('filter-skip-log.index');
    Route::post('/filter-skip-log/cleanup',     [App\Http\Controllers\Admin\FilterSkipLogController::class, 'cleanup'])->name('filter-skip-log.cleanup');

    // Fields: unified page — registry + source mappings + coverage stats.
    // Replaces legacy `/admin/accuracy` and `/admin/field-mappings`.
    Route::get('/fields',                        [FieldsController::class, 'index'])->name('fields');
    Route::post('/fields/recompute',             [FieldsController::class, 'recompute'])->name('fields.recompute');
    Route::get('/fields-schema.json',            [FieldsController::class, 'schema'])->name('fields.schema');

    // Taxonomy rules (Encar model/generation/trim curation)
    Route::get('/taxonomy',                      [TaxonomyRulesController::class, 'index'])->name('taxonomy.index');
    Route::post('/taxonomy/ingest',              [TaxonomyRulesController::class, 'ingest'])->name('taxonomy.ingest');
    Route::post('/taxonomy/rules',               [TaxonomyRulesController::class, 'storeRule'])->name('taxonomy.rules.store');
    Route::put('/taxonomy/rules/{id}',           [TaxonomyRulesController::class, 'updateRule'])->name('taxonomy.rules.update');
    Route::delete('/taxonomy/rules/{id}',        [TaxonomyRulesController::class, 'deleteRule'])->name('taxonomy.rules.delete');
    Route::patch('/taxonomy/queue/{id}',         [TaxonomyRulesController::class, 'updateQueue'])->name('taxonomy.queue.update');
    Route::post('/taxonomy/queue/{id}/create-rule', [TaxonomyRulesController::class, 'createRuleFromQueue'])->name('taxonomy.queue.create-rule');

    // Lots browse
    Route::get('/lots-browse',                   [AdminLotsController::class, 'browse'])->name('lots-browse');

    // Admin users management (super only)
    Route::get('/users',                         [AdminUsersController::class, 'index'])->name('users');
    Route::post('/users',                        [AdminUsersController::class, 'create'])->name('users.create');
    Route::delete('/users/{id}',                 [AdminUsersController::class, 'delete'])->name('users.delete');
    Route::patch('/users/{id}/password',         [AdminUsersController::class, 'updatePassword'])->name('users.password');
    Route::post('/users/permissions',            [AdminUsersController::class, 'updatePermissions'])->name('users.permissions');

    // Legacy redirects — keep old bookmarks working.
    Route::get('/accuracy',       fn () => redirect()->route('admin.fields'))->name('accuracy');
    Route::get('/field-mappings', fn () => redirect()->route('admin.fields'))->name('field.mappings');
});

Route::middleware('admin.auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/logs/tail', [LogsController::class, 'logsTail'])->name('logs.tail');
    Route::get('/logs/download', [LogsController::class, 'logsDownload'])->name('logs.download');
    Route::post('/logs/clear',   [LogsController::class, 'logsClear'])->name('logs.clear');
    Route::post('/logs/clear-jobs', [LogsController::class, 'logsClearJobs'])->name('logs.clear.jobs');
});

Route::get('/up', fn() => response('OK'));
