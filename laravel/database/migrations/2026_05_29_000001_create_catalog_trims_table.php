<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_trims', function (Blueprint $table) {
            $table->id();
            $table->string('trim_kr', 150);
            $table->string('trim_en', 150)->nullable();
            // '' = universal (any make), else canonical make name e.g. 'BMW'
            $table->string('make_en', 100)->default('');
            // Higher = matched first when multiple entries share the same trim_kr
            $table->unsignedTinyInteger('priority')->default(80);
            $table->timestamps();

            // A trim phrase is unique per (trim_kr, make_en) combination
            $table->unique(['trim_kr', 'make_en']);
            $table->index('make_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_trims');
    }
};
