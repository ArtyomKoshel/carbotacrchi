<?php

use App\Http\Controllers\Bot\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/bot/webhook', [WebhookController::class, 'handle'])
    ->middleware(['throttle:120,1', 'telegram.webhook']);

/*
 * The admin is the Filament panel at /admin (App\Providers\Filament\AdminPanelProvider).
 * Addresses of the old Blade admin, and /panel links from before the switch,
 * redirect to their new pages so bookmarks keep working.
 */
$platform = fn (string $tab) => fn () => redirect('/admin/platforms/' . (config('auction.sources')[0] ?? 'encar') . "?tab={$tab}", 301);

Route::get('/admin/lots-browse', fn () => redirect('/admin/lots', 301));
Route::get('/admin/changes', fn () => redirect('/admin/lot-changes', 301));
Route::get('/admin/stats', fn () => redirect('/admin/lot-changes', 301));
Route::get('/admin/jobs', $platform('runs'));
Route::get('/admin/jobs/{any}', $platform('runs'))->where('any', '.*');
Route::get('/admin/schedules', $platform('schedule'));
Route::get('/admin/filters', $platform('rules'));
Route::get('/admin/filter-skip-log', $platform('skips'));
Route::get('/admin/fields', fn () => redirect('/admin/field-coverage', 301));
Route::get('/admin/accuracy', fn () => redirect('/admin/field-coverage', 301));
Route::get('/admin/field-mappings', fn () => redirect('/admin/field-coverage', 301));
Route::get('/admin/bot-filters', fn () => redirect('/admin/search-settings', 301));
Route::get('/admin/users', fn () => redirect('/admin/admin-users', 301));
Route::get('/panel/{any?}', fn (?string $any = null) => redirect('/admin' . ($any ? "/{$any}" : ''), 301))->where('any', '.*');

Route::get('/up', fn() => response('OK'));
