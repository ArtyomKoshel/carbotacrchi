<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encar_option_catalog', function (Blueprint $table) {
            $table->string('code', 10)->primary();       // "001", "014"
            $table->string('name_kr', 100);              // "선루프"
            $table->string('name_en', 100)->nullable();  // "Sunroof"
            $table->string('name_ru', 100)->nullable();  // "Люк"
            $table->string('icon_url', 500)->nullable(); // CDN иконка от Encar
            $table->string('category', 40)->nullable();  // "comfort" | "safety" | "entertainment"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encar_option_catalog');
    }
};
