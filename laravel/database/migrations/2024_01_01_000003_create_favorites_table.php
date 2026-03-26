<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('lot_id', 100);
            $table->string('source', 20);
            $table->json('lot_data');
            $table->timestamps();
            $table->unique(['user_id', 'lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
