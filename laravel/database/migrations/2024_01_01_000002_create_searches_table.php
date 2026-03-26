<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('searches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index()->nullable();
            $table->string('make', 50)->nullable();
            $table->string('model', 50)->nullable();
            $table->smallInteger('year_from')->nullable();
            $table->smallInteger('year_to')->nullable();
            $table->integer('price_max')->nullable();
            $table->json('sources')->nullable();
            $table->integer('results_cnt')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('searches');
    }
};
