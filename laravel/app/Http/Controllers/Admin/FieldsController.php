<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldCoverageStat;
use App\Services\FieldMappingsService;
use App\Services\FieldRegistryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FieldsController extends Controller
{
    /** GET /admin/fields-schema.json — JSON export of the Python field registry (for UI JS). */
    public function schema(FieldRegistryService $registry): \Illuminate\Http\JsonResponse
    {
        return response()->json($registry->schema())
            ->header('Cache-Control', 'public, max-age=300');
    }

    /** GET /admin/fields — unified mapping catalogue + coverage stats. */
    public function index(FieldMappingsService $mappings, FieldRegistryService $registry)
    {
        $activeSources = array_values(array_filter(
            (array) config('auction.sources', ['encar']),
            fn ($source) => is_string($source) && $source !== '' && $source !== 'kbcha'
        ));

        $schema   = $mappings->schema();
        $mappingByAttr = [];
        foreach ($schema['mappings'] ?? [] as $m) {
            $mappingByAttr[$m['attribute']] = $m;
        }

        $registrySchema = $registry->schema();
        $registryByName = [];
        foreach ($registrySchema['fields'] ?? [] as $f) {
            $registryByName[$f['name']] = $f;
        }

        $coverageRaw = FieldCoverageStat::query()
            ->when(
                !empty($activeSources),
                fn ($q) => $q->whereIn('source', $activeSources),
                fn ($q) => $q->where('source', '!=', 'kbcha')
            )
            ->orderBy('source')
            ->orderBy('field_name')
            ->get();
        $coverageByField = [];
        $sources = [];
        $computedAt = null;
        foreach ($coverageRaw as $stat) {
            $coverageByField[$stat->field_name][$stat->source] = [
                'filled'  => $stat->filled_lots,
                'total'   => $stat->total_lots,
                'pct'     => $stat->coverage_pct,
            ];
            $sources[$stat->source] = true;
            if ($computedAt === null || $stat->computed_at->gt($computedAt)) {
                $computedAt = $stat->computed_at;
            }
        }
        $sources = array_keys($sources);
        sort($sources);

        $hiddenFields = array_values(array_filter(
            (array) config('admin.fields_hidden', []),
            fn ($f) => is_string($f) && $f !== ''
        ));
        $hiddenSet = array_fill_keys($hiddenFields, true);
        $lotColumns = array_fill_keys(Schema::getColumnListing('lots'), true);
        $virtualFields = ['photos' => true, 'inspection_record' => true];

        $unified = [];
        $allFieldNames = array_unique(array_merge(
            array_keys($registryByName),
            array_keys($mappingByAttr),
            array_keys($coverageByField),
        ));
        foreach ($allFieldNames as $name) {
            if (isset($hiddenSet[$name])) {
                continue;
            }
            $reg = $registryByName[$name] ?? null;
            $map = $mappingByAttr[$name] ?? null;
            $dbColumn = $map['db_column'] ?? $reg['column'] ?? $name;
            if (!isset($lotColumns[$dbColumn]) && !isset($virtualFields[$name])) {
                continue;
            }
            $unified[] = [
                'name'         => $name,
                'db_column'    => $dbColumn,
                'dtype'        => $reg['dtype']       ?? $map['dtype']      ?? '?',
                'category'     => $reg['category']    ?? $map['category']   ?? 'other',
                'filterable'   => (bool) ($reg['filterable'] ?? $map['filterable'] ?? false),
                'tracked'      => (bool) ($reg['tracked']    ?? false),
                'description'  => $reg['description'] ?? $map['notes'] ?? '',
                'extractions'  => $map['extractions'] ?? [],
                'coverage'     => $coverageByField[$name] ?? [],
            ];
        }

        usort($unified, fn($a, $b) => [$a['category'], $a['name']] <=> [$b['category'], $b['name']]);
        $grouped = [];
        foreach ($unified as $u) {
            $grouped[$u['category']][] = $u;
        }
        ksort($grouped);

        $totals = [];
        foreach ($sources as $src) {
            $totals[$src] = (int) DB::table('lots')
                ->where('source', $src)->where('is_active', 1)->count();
        }

        return view('admin.fields', [
            'grouped'     => $grouped,
            'sources'     => $sources,
            'totals'      => $totals,
            'totalFields' => count($unified),
            'computedAt'  => $computedAt,
            'version'     => $schema['version'] ?? 0,
        ]);
    }

    /** POST /admin/fields/recompute — run coverage job synchronously. */
    public function recompute()
    {
        $exportCode = Artisan::call('parser:export-fields');
        if ($exportCode !== 0) {
            $output = Artisan::output();
            Log::error('parser:export-fields failed', ['code' => $exportCode, 'output' => $output]);
            return redirect()->route('admin.fields')
                ->with('error', "Failed to refresh field schema (exit code {$exportCode}). Output: " . substr($output, 0, 500));
        }

        $coverageCode = Artisan::call('fields:compute-coverage');
        if ($coverageCode !== 0) {
            $output = Artisan::output();
            Log::error('fields:compute-coverage failed', ['code' => $coverageCode, 'output' => $output]);
            return redirect()->route('admin.fields')
                ->with('error', "Failed to recompute field coverage (exit code {$coverageCode}). Output: " . substr($output, 0, 500));
        }

        return redirect()->route('admin.fields')
            ->with('success', 'Field schema + coverage stats recomputed.');
    }
}
