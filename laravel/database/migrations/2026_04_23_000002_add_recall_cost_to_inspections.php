<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add has_recall, my_accident_cost, other_accident_cost to lot_inspections.
 * Previously these lived only inside the JSON `details` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_inspections', function (Blueprint $table) {
            $table->boolean('has_recall')->default(false)->after('has_tuning');
            $table->unsignedBigInteger('my_accident_cost')->nullable()->after('has_recall');
            $table->unsignedBigInteger('other_accident_cost')->nullable()->after('my_accident_cost');
        });
    }

    public function down(): void
    {
        Schema::table('lot_inspections', function (Blueprint $table) {
            $table->dropColumn(['has_recall', 'my_accident_cost', 'other_accident_cost']);
        });
    }
};
