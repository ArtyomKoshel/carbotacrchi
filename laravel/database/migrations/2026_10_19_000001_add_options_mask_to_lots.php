<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Option codes as a bitmask on `lots` instead of the `lot_options` pivot
 * (14.6M rows, ~1.9 GB for ~41 codes × 321k lots).
 *
 * - encar_option_catalog.bit: stable bit per code, assigned in code order.
 *   New codes get the next bit from `options:import-encar` (App\Support\OptionBits).
 * - lots.options_mask: bits 0..63; codes beyond that stay JSON-only.
 *
 * DDL only (ADD COLUMN at the end of `lots` is INSTANT): migrations run on
 * container start inside Railway's 30 s healthcheck window. Fill the mask with
 *   php artisan lots:compact-backfill --only=options
 * Until then OptionsSpec falls back to the options JSON for NULL masks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('encar_option_catalog', 'bit')) {
            Schema::table('encar_option_catalog', function (Blueprint $table) {
                $table->unsignedTinyInteger('bit')->nullable()->unique()->after('sort_order');
            });
        }

        $bit = (int) (DB::table('encar_option_catalog')->max('bit') ?? -1);
        foreach (DB::table('encar_option_catalog')->whereNull('bit')->orderBy('code')->pluck('code') as $code) {
            DB::table('encar_option_catalog')->where('code', $code)->update(['bit' => ++$bit]);
        }

        if (! Schema::hasColumn('lots', 'options_mask')) {
            Schema::table('lots', function (Blueprint $table) {
                $table->unsignedBigInteger('options_mask')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lots', 'options_mask')) {
            Schema::table('lots', fn (Blueprint $table) => $table->dropColumn('options_mask'));
        }
        if (Schema::hasColumn('encar_option_catalog', 'bit')) {
            Schema::table('encar_option_catalog', function (Blueprint $table) {
                $table->dropUnique(['bit']);
                $table->dropColumn('bit');
            });
        }
    }
};
