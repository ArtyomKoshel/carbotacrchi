<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_models', function (Blueprint $table) {
            $table->id();
            $table->string('make_kr', 100);
            $table->string('make_en', 100);
            $table->string('model_kr', 200);
            $table->timestamps();
            $table->unique(['make_kr', 'model_kr']);
            $table->index('make_en');
        });

        Schema::create('catalog_grades', function (Blueprint $table) {
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

        Schema::create('catalog_sub_grades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('grade_id');
            $table->string('sub_grade_kr', 300);
            $table->enum('type', ['trim', 'generation', 'unknown'])->default('unknown');
            $table->timestamps();
            $table->foreign('grade_id')->references('id')->on('catalog_grades')->onDelete('cascade');
            $table->unique(['grade_id', 'sub_grade_kr']);
        });

        // Known generation/chassis codes per model (LF, AD, HG, E36 …)
        Schema::create('catalog_model_generations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('model_id');
            $table->string('code', 20);
            $table->timestamps();
            $table->foreign('model_id')->references('id')->on('catalog_models')->onDelete('cascade');
            $table->unique(['model_id', 'code']);
        });

        // Token → tech-spec lookup tables (fuel, drive, body, gen_non_chassis, engine_family …)
        // Replaces all hardcoded DEFAULT_* arrays from PHP code.
        Schema::create('catalog_token_maps', function (Blueprint $table) {
            $table->id();
            $table->string('token', 100);          // lowercase, matched case-insensitively
            $table->string('token_type', 30);       // fuel|drive|body|gen_non_chassis|gen_exclude|engine_family|variant_exclude
            $table->string('mapped_value', 50)->nullable(); // null = exclusion list, else = canonical value
            $table->timestamps();
            $table->unique(['token', 'token_type']);
            $table->index('token_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_token_maps');
        Schema::dropIfExists('catalog_model_generations');
        Schema::dropIfExists('catalog_sub_grades');
        Schema::dropIfExists('catalog_grades');
        Schema::dropIfExists('catalog_models');
    }
};
