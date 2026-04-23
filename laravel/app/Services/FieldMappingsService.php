<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Fetches the per-parser field-mapping catalogue from the Python module
 * `parsers/_shared/field_mappings.py`.
 *
 * Strategy (in order):
 *   1. Read a pre-exported JSON file at `storage/app/field_mappings.json`.
 *   2. Otherwise invoke `python -m parsers._shared.field_mappings --json`
 *      and cache the result for 1 hour.
 *   3. On any failure return an empty catalogue (UI shows an explanation).
 */
class FieldMappingsService
{
    private const CACHE_KEY = 'parser:field-mappings:schema';
    private const CACHE_TTL = 3600;

    /**
     * Full catalogue:
     *   [
     *     'version'  => 1,
     *     'mappings' => [
     *       ['attribute'=>..., 'db_column'=>..., 'dtype'=>..., 'category'=>...,
     *        'filterable'=>bool, 'notes'=>..., 'extractions'=>[...]],
     *       ...
     *     ]
     *   ]
     */
    public function schema(): array
    {
        $file = storage_path('app/field_mappings.json');
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['mappings'])) {
                    return $decoded;
                }
            }
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->invokePython() ?? ['version' => 0, 'mappings' => []];
        });
    }

    /** Mappings grouped by category for nicer UI rendering. */
    public function groupedByCategory(): array
    {
        $groups = [];
        foreach ($this->schema()['mappings'] ?? [] as $m) {
            $cat = $m['category'] ?: 'other';
            $groups[$cat][] = $m;
        }
        ksort($groups);
        return $groups;
    }

    /** Unique list of sources that appear in extractions (e.g. ['encar','kbcha']). */
    public function sources(): array
    {
        $set = [];
        foreach ($this->schema()['mappings'] ?? [] as $m) {
            foreach ($m['extractions'] ?? [] as $e) {
                $set[$e['source']] = true;
            }
        }
        $sources = array_keys($set);
        sort($sources);
        return $sources;
    }

    private function invokePython(): ?array
    {
        $parserDir = env('PARSER_DIR', base_path('../parser'));
        $python    = env('PYTHON_BIN', 'python');
        if (!is_dir($parserDir)) {
            return null;
        }
        try {
            $result = Process::path($parserDir)->timeout(8)
                ->run([$python, '-m', 'parsers._shared.field_mappings', '--json']);
            if (!$result->successful()) {
                Log::warning('[field-mappings] python exited with ' . $result->exitCode());
                return null;
            }
            $decoded = json_decode(trim($result->output()), true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning('[field-mappings] process failed: ' . $e->getMessage());
            return null;
        }
    }
}
