<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen damage and secondary_damage from VARCHAR(60) to TEXT.
 * Korean panel descriptions from Encar inspection data can easily exceed 60 chars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->text('damage')->nullable()->change();
            $table->text('secondary_damage')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->string('damage', 60)->nullable()->change();
            $table->string('secondary_damage', 60)->nullable()->change();
        });
    }
};
