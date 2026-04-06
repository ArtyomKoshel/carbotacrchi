<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->unique();
            $table->boolean('enabled')->default(true);
            $table->string('schedule', 64)->default('');
            $table->unsignedInteger('interval_minutes')->default(60);
            $table->unsignedSmallInteger('max_pages')->default(0);
            $table->string('maker_filter', 128)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_schedules');
    }
};
