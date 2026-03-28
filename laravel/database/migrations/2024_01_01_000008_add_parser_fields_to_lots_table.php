<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->bigInteger('price_krw')->nullable()->after('lot_url');
            $table->boolean('is_active')->default(true)->index()->after('price_krw');
            $table->timestamp('parsed_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn(['price_krw', 'is_active', 'parsed_at']);
        });
    }
};
