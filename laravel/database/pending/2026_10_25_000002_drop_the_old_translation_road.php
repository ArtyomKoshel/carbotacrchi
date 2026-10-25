<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The old road — AI translations copied into lots.*_en, catalog_* tables for
 * lots:normalize-from-catalog — is gone: names come from the reference catalog (ref_*).
 * lots.trim_en stays: it is Encar's own English BadgeDetail (gradeDetailEnglishName).
 *
 * Indexes first, then the columns: an unindexed column drops instantly in MySQL 8.0.29+,
 * nothing on the 200k-row table is rebuilt. Dropped data is not restored by down().
 */
return new class extends Migration
{
    private const COLUMNS = ['make_en', 'model_group_en', 'model_en', 'badge_en', 'badge_group_en'];
    private const INDEXES = ['lots_make_en_index', 'lots_model_group_en_index', 'lots_model_en_index'];
    private const TABLES = ['catalog_badges', 'catalog_drive_tokens', 'catalog_model_trims', 'catalog_models',
        'catalog_model_generations', 'catalog_trims', 'translations'];

    public function up(): void
    {
        $indexes = array_column(Schema::getIndexes('lots'), 'name');
        foreach (self::INDEXES as $index) {
            if (in_array($index, $indexes, true)) {
                Schema::table('lots', fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
        $columns = array_values(array_filter(self::COLUMNS, fn (string $c) => Schema::hasColumn('lots', $c)));
        if ($columns !== []) {
            Schema::table('lots', fn (Blueprint $t) => $t->dropColumn($columns));
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $t) {
            $t->string('make_en', 60)->nullable()->index();
            $t->string('model_group_en', 100)->nullable()->index();
            $t->string('model_en', 100)->nullable()->index();
            $t->string('badge_en', 200)->nullable();
            $t->string('badge_group_en', 150)->nullable();
        });
    }
};
