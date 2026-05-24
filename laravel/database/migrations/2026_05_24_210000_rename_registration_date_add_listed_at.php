<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix date columns semantics:
 *
 * OLD:
 *   registration_date — was incorrectly filled with both "ad published date"
 *                       and "first vehicle registration date" (bug)
 *
 * NEW:
 *   first_reg_date — date of first vehicle registration (최초등록일)
 *   listed_at      — date the ad was published on the source site
 *
 * registration_year_month (YYYYMM int) stays unchanged — it's derived from
 * FormYear which is the first-registration year+month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            // Rename: registration_date → first_reg_date
            if (Schema::hasColumn('lots', 'registration_date')) {
                $table->renameColumn('registration_date', 'first_reg_date');
            }
        });

        Schema::table('lots', function (Blueprint $table) {
            // Add: listed_at — when the ad was published
            if (!Schema::hasColumn('lots', 'listed_at')) {
                $table->date('listed_at')->nullable()->after('first_reg_date');
                $table->index('listed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'listed_at')) {
                $table->dropIndex(['listed_at']);
                $table->dropColumn('listed_at');
            }
        });

        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'first_reg_date')) {
                $table->renameColumn('first_reg_date', 'registration_date');
            }
        });
    }
};
