<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align lots schema with Encar iNav hierarchy:
 *
 * DROP:
 *   - generation          (no such level in Encar; content is in model_en)
 *   - registration_year_month (disabled in filters; first_reg_date covers this)
 *
 * ADD:
 *   - badge_group         Encar Level 4 (e.g. "가솔린 3500cc")
 *   - badge_group_en      English translation of badge_group
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'generation')) {
                $table->dropColumn('generation');
            }
            if (Schema::hasColumn('lots', 'registration_year_month')) {
                $table->dropColumn('registration_year_month');
            }
            if (! Schema::hasColumn('lots', 'badge_group')) {
                $table->string('badge_group', 150)->nullable()->after('badge');
            }
            if (! Schema::hasColumn('lots', 'badge_group_en')) {
                $table->string('badge_group_en', 150)->nullable()->after('badge_group');
            }
        });

        // generation filter stays in bot_filter_settings but no longer maps to a DB
        // column — the search spec maps it to model_en instead. Disable card display.
        if (Schema::hasTable('bot_filter_settings')) {
            DB::table('bot_filter_settings')
                ->where('field_name', 'generation')
                ->update(['display_in_card' => false, 'updated_at' => now()]);

            DB::table('bot_filter_settings')
                ->where('field_name', 'registration_year_month')
                ->update(['enabled' => false, 'display_in_card' => false, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'badge_group_en')) {
                $table->dropColumn('badge_group_en');
            }
            if (Schema::hasColumn('lots', 'badge_group')) {
                $table->dropColumn('badge_group');
            }
            if (! Schema::hasColumn('lots', 'generation')) {
                $table->string('generation', 40)->nullable()->after('model_en');
            }
            if (! Schema::hasColumn('lots', 'registration_year_month')) {
                $table->unsignedInteger('registration_year_month')->nullable()->after('first_reg_date');
            }
        });
    }
};
