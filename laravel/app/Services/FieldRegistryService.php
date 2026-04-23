<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Fetches the CarLot field registry from the Python parser (parser/fields/registry.py).
 *
 * Strategy (in order):
 *   1. Read a pre-exported JSON file at `storage/app/fields.json` if present.
 *      This is the fastest path and works without a Python interpreter.
 *   2. Otherwise invoke `python -m fields.schema` via Process and cache
 *      the result for 1 hour.
 *   3. On any failure fall back to a minimal hardcoded list so the admin
 *      UI still renders.
 */
class FieldRegistryService
{
    private const CACHE_KEY = 'parser:fields:schema';
    private const CACHE_TTL = 3600;

    /**
     * Return the full schema as an associative array:
     *   ['version' => 1, 'fields' => [ [name, dtype, operators, enum_values, ...], ... ]]
     */
    public function schema(): array
    {
        $file = storage_path('app/fields.json');
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['fields'])) {
                    return $decoded;
                }
            }
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->invokePython() ?? $this->fallbackSchema();
        });
    }

    /** Convenience: only filterable fields, grouped by category for the UI. */
    public function groupedFilterable(): array
    {
        $schema = $this->schema();
        $groups = [];
        foreach ($schema['fields'] as $field) {
            if (empty($field['filterable'])) {
                continue;
            }
            $cat = $field['category'] ?? 'other';
            $groups[$cat][] = $field;
        }
        ksort($groups);
        return $groups;
    }

    /** Look up one field's metadata by name. */
    public function get(string $name): ?array
    {
        foreach ($this->schema()['fields'] ?? [] as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }
        return null;
    }

    private function invokePython(): ?array
    {
        $parserDir = env('PARSER_DIR', base_path('../parser'));
        $python    = env('PYTHON_BIN', 'python');
        if (!is_dir($parserDir)) {
            return null;
        }
        try {
            $result = Process::path($parserDir)
                ->timeout(5)
                ->run([$python, '-m', 'fields.schema']);
            if (!$result->successful()) {
                Log::warning('[fields-schema] python exited with ' . $result->exitCode());
                return null;
            }
            $json = trim($result->output());
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning('[fields-schema] process failed: ' . $e->getMessage());
            return null;
        }
    }

    private function fallbackSchema(): array
    {
        // Minimal subset just to keep the form usable if Python is unreachable.
        // Administrators should deploy storage/app/fields.json for a real run.
        return [
            'version' => 1,
            'fields'  => [
                ['name' => 'sell_type',       'dtype' => 'enum', 'category' => 'sales',      'filterable' => true, 'enum_values' => ['sale','auction','lease','rental','business','under_contract','insurance_hide'], 'operators' => ['eq','ne','in','not_in']],
                ['name' => 'mileage',         'dtype' => 'int',  'category' => 'condition',  'filterable' => true, 'operators' => ['eq','ne','gt','gte','lt','lte','between']],
                ['name' => 'year',            'dtype' => 'int',  'category' => 'identity',   'filterable' => true, 'operators' => ['eq','ne','gt','gte','lt','lte','between']],
                ['name' => 'price',           'dtype' => 'int',  'category' => 'price',      'filterable' => true, 'operators' => ['eq','ne','gt','gte','lt','lte','between']],
                ['name' => 'insurance_count', 'dtype' => 'int',  'category' => 'condition',  'filterable' => true, 'operators' => ['eq','ne','gt','gte','lt','lte']],
                ['name' => 'has_accident',    'dtype' => 'bool', 'category' => 'condition',  'filterable' => true, 'operators' => ['eq','ne']],
                ['name' => 'flood_history',   'dtype' => 'bool', 'category' => 'condition',  'filterable' => true, 'operators' => ['eq','ne']],
                ['name' => 'make',            'dtype' => 'string','category' => 'identity',  'filterable' => true, 'operators' => ['eq','ne','in','not_in','contains']],
            ],
        ];
    }
}
