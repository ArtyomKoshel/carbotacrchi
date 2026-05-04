<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BotFilterSetting;
use App\Services\ChatSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class BotFiltersController extends Controller
{
    /** GET /admin/bot-filters — manage bot filter/tolerance settings. */
    public function index()
    {
        if (!Schema::hasTable('bot_filter_settings')) {
            return redirect()->route('admin.dashboard')
                ->with('error', 'Table bot_filter_settings is missing. Run migrations first.');
        }

        if (BotFilterSetting::count() === 0) {
            Artisan::call('db:seed', ['--class' => 'BotFilterSettingsSeeder', '--force' => true]);
        }

        $settings = BotFilterSetting::orderBy('sort_order')->orderBy('field_name')->get();

        return view('admin.bot-filters', [
            'settings' => $settings,
            'categories' => $settings->groupBy(fn ($s) => $s->category ?: 'other'),
        ]);
    }

    /** POST /admin/bot-filters — save bot filter/tolerance settings. */
    public function update(Request $request)
    {
        if (!Schema::hasTable('bot_filter_settings')) {
            return redirect()->route('admin.dashboard')
                ->with('error', 'Table bot_filter_settings is missing. Run migrations first.');
        }

        $fields = $request->input('fields', []);
        $allowedTypes = ['none', 'absolute', 'percentage'];

        foreach ($fields as $id => $data) {
            $setting = BotFilterSetting::find((int) $id);
            if (!$setting) {
                continue;
            }

            $toleranceType = $data['tolerance_type'] ?? 'none';
            if (!in_array($toleranceType, $allowedTypes, true)) {
                $toleranceType = 'none';
            }

            $toleranceValue = null;
            $rawValue = $data['tolerance_value'] ?? null;
            if ($toleranceType !== 'none' && $rawValue !== null && $rawValue !== '' && is_numeric($rawValue)) {
                $toleranceValue = (float) $rawValue;
                if ($toleranceType === 'percentage') {
                    if ($toleranceValue > 1) {
                        $toleranceValue /= 100;
                    }
                    $toleranceValue = max(0, min(1, $toleranceValue));
                } else {
                    $toleranceValue = max(0, $toleranceValue);
                }
            }

            if (!in_array($setting->dtype, ['int', 'float', 'date'], true)) {
                $toleranceType = 'none';
                $toleranceValue = null;
            }

            $setting->update([
                'enabled' => !empty($data['enabled']),
                'tolerance_type' => $toleranceType,
                'tolerance_value' => $toleranceValue,
                'display_in_card' => !empty($data['display_in_card']),
            ]);
        }

        return redirect()->route('admin.bot-filters')
            ->with('success', 'Настройки бот-фильтров сохранены');
    }

    /** POST /admin/bot-filters/reset — reset bot filter settings to defaults. */
    public function reset()
    {
        if (!Schema::hasTable('bot_filter_settings')) {
            return redirect()->route('admin.dashboard')
                ->with('error', 'Table bot_filter_settings is missing. Run migrations first.');
        }

        BotFilterSetting::truncate();
        Artisan::call('db:seed', ['--class' => 'BotFilterSettingsSeeder', '--force' => true]);

        return redirect()->route('admin.bot-filters')
            ->with('success', 'Настройки сброшены к дефолтным');
    }

    /** POST /admin/bot-filters/preview — preview bot parsing/tolerance on free text. */
    public function preview(Request $request)
    {
        $text = trim((string) $request->input('text', ''));
        if ($text === '') {
            return response()->json(['ok' => false, 'error' => 'Введите текст запроса'], 422);
        }

        $chatSearch = new ChatSearchService();
        $parsed = $chatSearch->parseAndSearch($text);

        if ($parsed === null) {
            return response()->json(['ok' => false, 'error' => 'Не удалось распарсить запрос'], 422);
        }

        /** @var \App\Services\SearchQuery $query */
        $query = $parsed['query'];
        /** @var \App\Services\SearchQuery $tolerantQuery */
        $tolerantQuery = $parsed['tolerantQuery'];

        return response()->json([
            'ok' => true,
            'data' => [
                'mode' => ($parsed['isAi'] ?? false) ? 'ai' : 'fallback',
                'description' => (string) ($parsed['description'] ?? ''),
                'toleranceNote' => (string) ($parsed['toleranceNote'] ?? ''),
                'parsed' => $query->toSearchArray(),
                'tolerant' => $tolerantQuery->toSearchArray(),
            ],
        ]);
    }
}
