<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Drop unused import_type and is_domestic columns from lots table.
     * These fields are not populated by parsers and have no business value.
     */
    public function up(): void
    {
        // Drop columns if they exist (safe for different schema states)
        if (Schema::hasColumn('lots', 'import_type')) {
            Schema::table('lots', function (Blueprint $table) {
                $table->dropColumn('import_type');
            });
        }

        if (Schema::hasColumn('lots', 'is_domestic')) {
            Schema::table('lots', function (Blueprint $table) {
                $table->dropColumn('is_domestic');
            });
        }

        // Clean up stale coverage stats for these removed fields
        if (Schema::hasTable('field_coverage_stats')) {
            DB::table('field_coverage_stats')
                ->whereIn('field', ['import_type', 'is_domestic'])
                ->delete();
        }
    }

    /**
     * Re-add columns for rollback (if needed).
     */
    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->boolean('is_domestic')->nullable()->after('seat_count');
            $table->string('import_type')->nullable()->after('is_domestic');
        });
    }
};
