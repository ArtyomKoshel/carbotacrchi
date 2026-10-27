<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The calculator's defaults as the admin set them (key → value); missing keys use config('calculator.defaults'). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculator_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->string('value', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculator_settings');
    }
};
