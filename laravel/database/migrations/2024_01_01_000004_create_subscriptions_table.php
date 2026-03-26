<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->json('query');
            $table->json('known_lot_ids')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['user_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
