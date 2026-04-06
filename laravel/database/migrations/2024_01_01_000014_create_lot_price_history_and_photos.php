<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Generic field-change history -------------------------------------
        // One row per parse cycle per lot when ANY tracked field changes.
        // `changes` JSON format:
        //   {"price": {"old": 55500000, "new": 54000000}, "mileage": {"old": 7988, "new": 9500}}
        // `event` describes the context:
        //   "update"   — regular re-parse, field values changed
        //   "delisted" — lot became inactive (is_active 1→0)
        //   "relisted" — lot became active again (is_active 0→1)
        Schema::create('lot_changes', function (Blueprint $table) {
            $table->id();
            $table->string('lot_id', 64)->index();
            $table->string('source', 32)->index();
            $table->string('event', 32)->default('update');
            $table->json('changes');
            $table->timestamp('recorded_at')->useCurrent()->index();

            $table->foreign('lot_id')->references('id')->on('lots')->onDelete('cascade');
        });

        // --- Photo gallery ----------------------------------------------------
        // All gallery images parsed from the detail page.
        // position=0 is the main photo (mirrors lots.image_url).
        Schema::create('lot_photos', function (Blueprint $table) {
            $table->id();
            $table->string('lot_id', 64)->index();
            $table->string('url', 1024);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('lot_id')->references('id')->on('lots')->onDelete('cascade');
            $table->unique(['lot_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_photos');
        Schema::dropIfExists('lot_changes');
    }
};
