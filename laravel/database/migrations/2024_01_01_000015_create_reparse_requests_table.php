<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reparse_requests', function (Blueprint $table) {
            $table->id();
            $table->string('lot_id', 64)->index();
            $table->enum('status', ['pending', 'running', 'done', 'error'])->default('pending')->index();
            $table->text('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reparse_requests');
    }
};
