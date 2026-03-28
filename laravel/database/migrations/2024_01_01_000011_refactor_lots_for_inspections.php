<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            // accident_status varchar(30) → has_accident bool
            $table->boolean('has_accident')->nullable()->after('secondary_damage');

            // dealer fields (keep for card display)
            // plate_number, registration_date already exist
        });

        // Drop accident_status after adding has_accident
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn([
                'accident_status',
                'total_loss_history',
                'lien_status',
                'seizure_status',
                'tax_paid',
                'cylinders',
                'new_car_price_ratio',
                'ai_price_min',
                'ai_price_max',
                'mileage_grade',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn('has_accident');

            $table->string('accident_status', 30)->nullable();
            $table->boolean('total_loss_history')->nullable();
            $table->string('lien_status', 20)->nullable();
            $table->string('seizure_status', 20)->nullable();
            $table->boolean('tax_paid')->nullable();
            $table->unsignedTinyInteger('cylinders')->nullable();
            $table->unsignedSmallInteger('new_car_price_ratio')->nullable();
            $table->unsignedInteger('ai_price_min')->nullable();
            $table->unsignedInteger('ai_price_max')->nullable();
            $table->string('mileage_grade', 20)->nullable();
        });
    }
};
