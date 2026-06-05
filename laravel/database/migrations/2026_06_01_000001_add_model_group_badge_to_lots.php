<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add model_group and badge as first-class columns to lots.
 *
 * model_group  = Encar ModelGroup (level 2 iNav): "쏘나타", "3 Series"
 * badge        = Encar Badge (level 5 iNav):       "가솔린 2.5T 4WD"
 *
 * Both come directly from the search API — no parsing needed.
 * Backfill from raw_data where available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (! Schema::hasColumn('lots', 'model_group')) {
                $table->string('model_group', 100)->nullable()->after('model_en');
                $table->index('model_group');
            }
            if (! Schema::hasColumn('lots', 'badge')) {
                $table->string('badge', 150)->nullable()->after('trim');
                $table->index('badge');
            }
        });

        // Backfill model_group from raw_data.model_group_kr
        DB::statement(<<<'SQL'
            UPDATE lots
            SET model_group = JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.model_group_kr'))
            WHERE JSON_EXTRACT(raw_data, '$.model_group_kr') IS NOT NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.model_group_kr')) != ''
              AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.model_group_kr')) != 'null'
              AND model_group IS NULL
        SQL);

        // Backfill badge from raw_data.badge_kr
        DB::statement(<<<'SQL'
            UPDATE lots
            SET badge = JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_kr'))
            WHERE JSON_EXTRACT(raw_data, '$.badge_kr') IS NOT NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_kr')) != ''
              AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_kr')) != 'null'
              AND badge IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'model_group')) {
                $table->dropIndex(['model_group']);
                $table->dropColumn('model_group');
            }
            if (Schema::hasColumn('lots', 'badge')) {
                $table->dropIndex(['badge']);
                $table->dropColumn('badge');
            }
        });
    }
};
