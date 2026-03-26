<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->string('id', 100)->primary();
            $table->string('source', 20)->index();
            $table->string('make', 60)->index();
            $table->string('model', 60)->index();
            $table->unsignedSmallInteger('year')->index();
            $table->unsignedInteger('price')->index();
            $table->unsignedInteger('mileage')->default(0);
            $table->string('vin', 17)->nullable()->index();

            $table->string('body_type', 30)->nullable()->index();
            $table->string('transmission', 20)->nullable();
            $table->string('fuel', 20)->nullable();
            $table->string('drive_type', 10)->nullable();

            $table->string('damage', 60)->nullable();
            $table->string('secondary_damage', 60)->nullable();
            $table->string('title', 40)->nullable();
            $table->string('document', 100)->nullable();
            $table->string('location', 120)->nullable();
            $table->string('color', 30)->nullable();
            $table->string('trim', 40)->nullable();

            $table->float('engine_volume')->nullable();
            $table->unsignedTinyInteger('cylinders')->nullable();
            $table->boolean('has_keys')->nullable();
            $table->unsignedInteger('retail_value')->nullable();
            $table->unsignedInteger('repair_cost')->nullable();

            $table->string('image_url', 500)->nullable();
            $table->string('lot_url', 500)->nullable();

            $table->json('raw_data')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
    }
};
