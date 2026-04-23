<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-computed field coverage per (source, field_name).
 *
 * The /admin/fields (ex-Accuracy) page reads this table instead of running
 * full-table aggregate SQL with JSON_EXTRACT on every pageload. Recomputed
 * hourly via `php artisan fields:compute-coverage`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_coverage_stats', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);          // 'encar' / 'kbcha' / ...
            $table->string('field_name', 64);      // CarLot attribute name
            $table->unsignedBigInteger('total_lots');
            $table->unsignedBigInteger('filled_lots');
            $table->decimal('coverage_pct', 5, 1); // 0.0 .. 100.0
            $table->timestamp('computed_at')->useCurrent();

            $table->unique(['source', 'field_name']);
            $table->index('source');
            $table->index('coverage_pct');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_coverage_stats');
    }
};
