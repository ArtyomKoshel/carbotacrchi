<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds catalog_trims and catalog_model_trims from pre-generated SQL files.
 *
 * catalog_trims        — static list of valid trim names per make (953 rows)
 * catalog_model_trims  — exact badge → trim mapping from Encar iNav (11,559 rows)
 *
 * Both SQL files use ON DUPLICATE KEY UPDATE — safe to re-run.
 *
 * Usage:
 *   php artisan db:seed --class=CatalogSeeder
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $files = [
            'catalog_trims'       => database_path('seeders/data/catalog_trims_seed.sql'),
            'catalog_model_trims' => database_path('seeders/data/catalog_model_trims_seed.sql'),
        ];

        foreach ($files as $table => $path) {
            if (! file_exists($path)) {
                $this->command->warn("  [skip] {$table}: seed file not found at {$path}");
                continue;
            }

            $before = DB::table($table)->count();
            $raw    = file_get_contents($path);

            // Strip comment-only lines before splitting
            $sql = implode("\n", array_filter(
                explode("\n", $raw),
                fn($line) => ! str_starts_with(ltrim($line), '--')
            ));

            // Split into individual statements and execute one by one
            $count = 0;
            foreach (explode(";\n", $sql) as $statement) {
                $statement = trim(rtrim($statement, ';'));
                if (empty($statement)) {
                    continue;
                }
                DB::unprepared($statement);
                $count++;
            }

            $after = DB::table($table)->count();
            $this->command->info("  {$table}: {$before} → {$after} rows ({$count} batches)");
        }
    }
}
