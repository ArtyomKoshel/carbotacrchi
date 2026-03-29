<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (!Schema::hasColumn('lots', 'fuel_economy'))
                $table->decimal('fuel_economy', 5, 1)->unsigned()->nullable()->after('engine_volume');
            if (!Schema::hasColumn('lots', 'paid_options'))
                $table->json('paid_options')->nullable()->after('options');
            if (!Schema::hasColumn('lots', 'warranty_text'))
                $table->string('warranty_text', 80)->nullable()->after('dealer_description');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn(['fuel_economy', 'paid_options', 'warranty_text']);
        });
    }
};
