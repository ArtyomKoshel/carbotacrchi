<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Compute field-fill coverage per source and cache it in `field_coverage_stats`.
 *
 * Run hourly via the scheduler:
 *   $schedule->command('fields:compute-coverage')->hourly();
 *
 * The admin `/admin/fields` page reads from this table directly, keeping
 * pageload fast regardless of `lots` table size.
 */
class ComputeFieldCoverage extends Command
{
    protected $signature = 'fields:compute-coverage {--source= : Only recompute for this source}';
    protected $description = 'Recompute field-fill coverage stats for the admin Fields page';

    /**
     * Columns on `lots` whose coverage we report. SQL predicate templates
     * use placeholder `X` which is replaced with the actual column name.
     */
    private const CHECKS = [
        // Primary identity
        ['field' => 'make',              'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'model',             'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'year',              'pred' => "X IS NOT NULL AND X > 0"],
        ['field' => 'trim',              'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'vin',               'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'plate_number',      'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'registration_date',       'pred' => "X IS NOT NULL"],
        ['field' => 'registration_year_month', 'pred' => "X IS NOT NULL AND X > 0"],

        // Pricing
        ['field' => 'price',             'pred' => "X IS NOT NULL AND X > 0"],
        ['field' => 'retail_value',      'pred' => "X IS NOT NULL AND X > 0"],
        ['field' => 'repair_cost',       'pred' => "X IS NOT NULL"],

        // Odometer
        ['field' => 'mileage',           'pred' => "X IS NOT NULL AND X > 0"],
        ['field' => 'mileage_grade',     'pred' => "X IS NOT NULL AND X <> ''"],

        // Technical specs
        ['field' => 'fuel',              'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'transmission',      'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'body_type',         'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'drive_type',        'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'engine_volume',     'pred' => "X IS NOT NULL AND X > 0"],
        ['field' => 'fuel_economy',      'pred' => "X IS NOT NULL AND X > 0"],
        ['field' => 'cylinders',         'pred' => "X IS NOT NULL AND X > 0"],
        ['field' => 'color',             'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'seat_color',        'pred' => "X IS NOT NULL AND X <> ''"],

        // Sales model (P1 + P2 filter)
        ['field' => 'sell_type',         'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'sell_type_raw',     'pred' => "X IS NOT NULL AND X <> ''"],

        // Legal
        ['field' => 'lien_status',       'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'seizure_status',    'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'tax_paid',          'pred' => "X IS NOT NULL"],

        // Condition & history
        ['field' => 'has_accident',      'pred' => "X IS NOT NULL"],
        ['field' => 'flood_history',     'pred' => "X IS NOT NULL"],
        ['field' => 'total_loss_history','pred' => "X IS NOT NULL"],
        ['field' => 'owners_count',      'pred' => "X IS NOT NULL"],
        ['field' => 'insurance_count',   'pred' => "X IS NOT NULL"],
        ['field' => 'damage',            'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'secondary_damage',  'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'warranty_text',     'pred' => "X IS NOT NULL AND X <> ''"],

        // Options
        ['field' => 'options',           'pred' => "X IS NOT NULL AND JSON_LENGTH(X) > 0"],
        ['field' => 'paid_options',      'pred' => "X IS NOT NULL AND JSON_LENGTH(X) > 0"],

        // Location & links
        ['field' => 'location',          'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'image_url',         'pred' => "X IS NOT NULL AND X <> ''"],

        // Dealer
        ['field' => 'dealer_name',       'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'dealer_company',    'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'dealer_phone',      'pred' => "X IS NOT NULL AND X <> ''"],
        ['field' => 'dealer_location',   'pred' => "X IS NOT NULL AND X <> ''"],
    ];

    /**
     * Virtual fields computed via JOINs (not a column on `lots`).
     * Value is the join-target table with an id column `lot_id`.
     */
    private const JOINED_CHECKS = [
        'photos'            => 'lot_photos',
        'inspection_record' => 'lot_inspections',
    ];

    public function handle(): int
    {
        $only = $this->option('source');
        $sources = $only
            ? [$only]
            : DB::table('lots')->where('is_active', 1)->distinct()->pluck('source')->all();

        if (empty($sources)) {
            $this->warn('No active sources found in `lots`.');
            return self::SUCCESS;
        }

        $computedAt = now();
        $totalRows  = 0;

        foreach ($sources as $source) {
            $this->info("Computing coverage for source={$source} ...");
            $totalLots = (int) DB::table('lots')
                ->where('source', $source)
                ->where('is_active', 1)
                ->count();

            if ($totalLots === 0) {
                $this->line("  no active lots — skipping");
                continue;
            }

            // Build a single aggregated SELECT with SUM(predicate) per field.
            // This is O(N) — ONE full table scan per source, not N separate ones.
            $selects = [];
            foreach (self::CHECKS as $c) {
                $col = $c['field'];
                $pred = str_replace('X', "`{$col}`", $c['pred']);
                $alias = $this->safeAlias($col);
                $selects[] = "SUM({$pred}) AS {$alias}";
            }
            $sql = "SELECT " . implode(", ", $selects)
                 . " FROM lots WHERE source = ? AND is_active = 1";
            $row = (array) DB::selectOne($sql, [$source]);

            // Insert / update one row per field
            $rows = [];
            foreach (self::CHECKS as $c) {
                $alias = $this->safeAlias($c['field']);
                $filled = (int) ($row[$alias] ?? 0);
                $rows[] = [
                    'source'       => $source,
                    'field_name'   => $c['field'],
                    'total_lots'   => $totalLots,
                    'filled_lots'  => $filled,
                    'coverage_pct' => $totalLots > 0 ? round($filled / $totalLots * 100, 1) : 0,
                    'computed_at'  => $computedAt,
                ];
            }

            // Virtual / joined fields — lot_photos, lot_inspections.
            foreach (self::JOINED_CHECKS as $name => $joinTable) {
                $filled = (int) DB::selectOne(
                    "SELECT COUNT(DISTINCT lot_id) AS c FROM {$joinTable} "
                    . "WHERE lot_id IN (SELECT id FROM lots WHERE source = ? AND is_active = 1)",
                    [$source]
                )->c;
                $rows[] = [
                    'source'       => $source,
                    'field_name'   => $name,
                    'total_lots'   => $totalLots,
                    'filled_lots'  => $filled,
                    'coverage_pct' => $totalLots > 0 ? round($filled / $totalLots * 100, 1) : 0,
                    'computed_at'  => $computedAt,
                ];
            }

            DB::table('field_coverage_stats')->upsert(
                $rows,
                ['source', 'field_name'],
                ['total_lots', 'filled_lots', 'coverage_pct', 'computed_at'],
            );
            $totalRows += count($rows);
            $this->info('  wrote ' . count($rows) . ' rows (' . number_format($totalLots) . ' lots scanned)');
        }

        $this->info('Done. ' . number_format($totalRows) . ' rows upserted in field_coverage_stats.');
        return self::SUCCESS;
    }

    /** Column name → safe SQL alias. */
    private function safeAlias(string $col): string
    {
        return 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $col);
    }
}
