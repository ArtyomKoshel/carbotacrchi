<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compact record of lots removed by `lots:lifecycle` (~200 bytes per car).
 * Keeps sold-price history after the lot row, its photos, options,
 * inspection and change log are deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots_archive', function (Blueprint $table) {
            $table->string('id', 100)->primary();
            $table->string('source', 20);
            $table->string('make_en', 100)->nullable();
            $table->string('model_group_en', 100)->nullable();
            $table->string('model_en', 100)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->unsignedBigInteger('price')->nullable();
            $table->timestamp('first_seen')->nullable();        // lots.created_at
            $table->timestamp('last_seen')->nullable();         // lots.parsed_at
            $table->timestamp('sold_detected_at')->nullable();  // last 'delisted' lot_changes event
            $table->timestamp('archived_at')->useCurrent();

            $table->index(['make_en', 'model_group_en', 'year'], 'lots_archive_model_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots_archive');
    }
};
