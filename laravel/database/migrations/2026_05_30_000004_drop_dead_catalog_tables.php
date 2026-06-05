<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop catalog tables that were never actively used and are documented as dead code.
 *
 * catalog_grades     — grade-exact-match lookup was removed (CatalogLookupService docblock).
 *                      GradeExtractorService skips step 2 in practice; CatalogImport only
 *                      truncates it, never queries it for extraction.
 *
 * catalog_sub_grades — no service ever SELECTs from this table; only a Model class exists.
 *
 * Replacement: GradeExtractorService uses catalog_trims (positive trim matching) instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        // sub_grades first (FK → catalog_grades)
        Schema::dropIfExists('catalog_sub_grades');
        Schema::dropIfExists('catalog_grades');
    }

    public function down(): void
    {
        // Restore from create_catalog_tables migration if needed
        Schema::create('catalog_grades', function ($table) {
            $table->id();
            $table->unsignedBigInteger('model_id');
            $table->string('grade_kr', 300);
            $table->string('fuel_type', 30)->nullable();
            $table->string('drive_type', 20)->nullable();
            $table->decimal('engine_volume', 4, 1)->nullable();
            $table->unsignedTinyInteger('seat_count')->nullable();
            $table->unsignedTinyInteger('cylinders')->nullable();
            $table->string('body_hint', 30)->nullable();
            $table->timestamps();
            $table->foreign('model_id')->references('id')->on('catalog_models')->onDelete('cascade');
            $table->unique(['model_id', 'grade_kr']);
        });

        Schema::create('catalog_sub_grades', function ($table) {
            $table->id();
            $table->unsignedBigInteger('grade_id');
            $table->string('sub_grade_kr', 300);
            $table->enum('type', ['trim', 'generation', 'unknown'])->default('unknown');
            $table->timestamps();
            $table->foreign('grade_id')->references('id')->on('catalog_grades')->onDelete('cascade');
            $table->unique(['grade_id', 'sub_grade_kr']);
        });
    }
};
