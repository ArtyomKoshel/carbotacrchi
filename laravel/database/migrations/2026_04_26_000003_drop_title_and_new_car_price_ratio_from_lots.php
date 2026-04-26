<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Drop unused title and new_car_price_ratio columns from lots table.
     * These fields have no business value and are not populated by parsers.
     */
    public function up(): void
    {
        // Drop columns if they exist (safe for different schema states)
        if (Schema::hasColumn('lots', 'title')) {
            Schema::table('lots', function (Blueprint $table) {
                $table->dropColumn('title');
            });
        }

        if (Schema::hasColumn('lots', 'new_car_price_ratio')) {
            Schema::table('lots', function (Blueprint $table) {
                $table->dropColumn('new_car_price_ratio');
            });
        }

        // Clean up stale coverage stats for these removed fields
        if (Schema::hasTable('field_coverage_stats')) {
            DB::table('field_coverage_stats')
                ->whereIn('field', ['title', 'new_car_price_ratio'])
                ->delete();
        }
    }

    /**
     * Re-add columns for rollback (if needed).
     */
    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->string('title')->default('Clean')->nullable()->after('insurance_count');
            $table->integer('new_car_price_ratio')->nullable()->after('repair_cost');
        });
    }
};
