<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_filter_settings', function (Blueprint $table) {
            $table->id();
            $table->string('field_name', 50)->unique();
            $table->string('field_label', 100)->default('');
            $table->string('dtype', 20)->default('string');
            $table->string('category', 30)->default('');
            $table->boolean('enabled')->default(false);
            $table->string('tolerance_type', 20)->default('none');
            $table->decimal('tolerance_value', 12, 4)->nullable();
            $table->boolean('display_in_card')->default(false);
            $table->json('enum_values')->nullable();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['enabled', 'sort_order']);
            $table->index(['display_in_card', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_filter_settings');
    }
};
