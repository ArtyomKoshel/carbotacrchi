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
            $sql    = file_get_contents($path);

            // Split on statement boundaries and execute each batch
            $statements = array_filter(
                array_map('trim', explode(";\n", $sql)),
                fn($s) => ! empty($s) && ! str_starts_with($s, '--')
            );

            DB::unprepared(implode(";\n", $statements) . ';');

            $after = DB::table($table)->count();
            $this->command->info("  {$table}: {$before} → {$after} rows");
        }
    }
}
