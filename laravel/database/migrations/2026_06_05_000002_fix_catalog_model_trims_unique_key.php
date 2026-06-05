<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix the unique key on catalog_model_trims that exceeded MySQL's 3072-byte limit.
 *
 * The original migration failed to add the unique index because
 * 6 × utf8mb4 columns × up to 191 chars × 4 bytes = ~3620 bytes > 3072 limit.
 *
 * Solution: use prefix lengths in the index so the total stays under 3072.
 *   source(32) + make_kr(60) + model_group_kr(100) + badge_exact(100) + badge_group_exact(100) + trim_kr(100)
 *   = (32+60+100+100+100+100) × 4 = 2368 bytes — well within limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop if it somehow partially exists
        try {
            Schema::table('catalog_model_trims', function ($table) {
                $table->dropUnique('catalog_model_trims_unique');
            });
        } catch (\Throwable) {
            // Index didn't exist — that's fine
        }

        DB::statement('
            ALTER TABLE catalog_model_trims
            ADD UNIQUE INDEX catalog_model_trims_unique (
                source(32),
                make_kr(60),
                model_group_kr(100),
                badge_exact(100),
                badge_group_exact(100),
                trim_kr(100)
            )
        ');
    }

    public function down(): void
    {
        Schema::table('catalog_model_trims', function ($table) {
            $table->dropUnique('catalog_model_trims_unique');
        });
    }
};
