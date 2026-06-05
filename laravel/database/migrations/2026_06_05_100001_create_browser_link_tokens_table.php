<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_link_tokens', function (Blueprint $table) {
            $table->string('token', 64)->primary();
            $table->bigInteger('chat_id')->nullable()->index();
            $table->string('first_name', 100)->nullable();
            $table->string('username', 100)->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_link_tokens');
    }
};
