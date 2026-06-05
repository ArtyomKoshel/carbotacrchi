<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add unknown_trim_part column to catalog_model_trims.
 *
 * Stores ambiguous trim fragments that could not be confidently
 * classified as a main trim level — e.g. option packages (모젠팩,
 * 알칸타라팩), sub-variants (럭셔리 I, VIP팩), or legacy pack
 * designators from older iNav entries.
 *
 * NULL  = no ambiguous part (clean trim entry)
 * value = the raw fragment kept for reference / future resolution
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_model_trims', function (Blueprint $table) {
            $table->string('unknown_trim_part', 191)
                ->nullable()
                ->after('trim_en')
                ->comment('Ambiguous trim fragment (package, sub-variant, legacy pack) — not a main trim level');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_model_trims', function (Blueprint $table) {
            $table->dropColumn('unknown_trim_part');
        });
    }
};
