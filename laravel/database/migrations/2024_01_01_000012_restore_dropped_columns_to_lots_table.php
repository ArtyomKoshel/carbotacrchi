<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->tinyInteger('cylinders')->unsigned()->nullable()->after('engine_volume');

            $table->string('lien_status', 30)->nullable()->after('registration_date');
            $table->string('seizure_status', 30)->nullable()->after('lien_status');
            $table->boolean('tax_paid')->nullable()->after('seizure_status');

            $table->boolean('total_loss_history')->nullable()->after('flood_history');
            $table->string('mileage_grade', 20)->nullable()->after('insurance_count');

            $table->smallInteger('new_car_price_ratio')->unsigned()->nullable()->after('repair_cost');
            $table->integer('ai_price_min')->unsigned()->nullable()->after('new_car_price_ratio');
            $table->integer('ai_price_max')->unsigned()->nullable()->after('ai_price_min');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn([
                'cylinders',
                'lien_status',
                'seizure_status',
                'tax_paid',
                'total_loss_history',
                'mileage_grade',
                'new_car_price_ratio',
                'ai_price_min',
                'ai_price_max',
            ]);
        });
    }
};
