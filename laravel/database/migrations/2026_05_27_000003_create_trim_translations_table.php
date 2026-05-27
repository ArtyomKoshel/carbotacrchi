<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General localization/translation table for Korean field values.
 *
 * Categories:
 *   trim         – badge_detail_kr values  (프레스티지 → Prestige)
 *   color        – exterior color strings  (은회색 → Silver Gray)
 *   seat_color   – interior color strings  (갈색 계열 → Beige / Brown)
 *   option       – option codes            (001 → Driver airbag)
 *   model_prefix – Korean model prefixes   (더 뉴 → The New, 올 뉴 → All New)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50)->comment('trim|color|seat_color|option|model_prefix');
            $table->string('kr', 200)->comment('Korean source value');
            $table->string('en', 200)->nullable()->comment('English translation');
            $table->string('ru', 200)->nullable()->comment('Russian translation');
            $table->string('source', 50)->default('manual')->comment('encar_official|manual|auto');
            $table->timestamps();

            $table->unique(['category', 'kr']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
