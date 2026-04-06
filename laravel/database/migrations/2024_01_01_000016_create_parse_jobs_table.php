<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parse_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->index();
            $table->enum('status', ['pending', 'running', 'done', 'error', 'cancelled'])->default('pending')->index();
            $table->json('filters')->nullable();
            $table->json('progress')->nullable();
            $table->json('result')->nullable();
            $table->string('triggered_by', 64)->default('admin');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parse_jobs');
    }
};
