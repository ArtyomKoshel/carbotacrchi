<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            // Registration & documents
            $table->string('plate_number', 20)->nullable()->after('vin');
            $table->string('registration_date', 30)->nullable()->after('plate_number');
            $table->string('lien_status', 20)->nullable()->after('document');
            $table->string('seizure_status', 20)->nullable()->after('lien_status');
            $table->boolean('tax_paid')->nullable()->after('seizure_status');

            // Condition & history
            $table->string('accident_status', 30)->nullable()->after('secondary_damage');
            $table->boolean('total_loss_history')->nullable()->after('accident_status');
            $table->boolean('flood_history')->nullable()->after('total_loss_history');
            $table->unsignedTinyInteger('owners_count')->nullable()->after('flood_history');
            $table->unsignedTinyInteger('insurance_count')->nullable()->after('owners_count');
            $table->string('mileage_grade', 20)->nullable()->after('insurance_count');

            // Comfort
            $table->string('seat_color', 30)->nullable()->after('color');
            $table->json('options')->nullable()->after('trim');

            // Pricing
            $table->unsignedSmallInteger('new_car_price_ratio')->nullable()->after('repair_cost');
            $table->unsignedInteger('ai_price_min')->nullable()->after('new_car_price_ratio');
            $table->unsignedInteger('ai_price_max')->nullable()->after('ai_price_min');

            // Dealer
            $table->string('dealer_name', 50)->nullable()->after('lot_url');
            $table->string('dealer_company', 60)->nullable()->after('dealer_name');
            $table->string('dealer_location', 200)->nullable()->after('dealer_company');
            $table->string('dealer_phone', 30)->nullable()->after('dealer_location');
            $table->text('dealer_description')->nullable()->after('dealer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn([
                'plate_number', 'registration_date',
                'lien_status', 'seizure_status', 'tax_paid',
                'accident_status', 'total_loss_history', 'flood_history',
                'owners_count', 'insurance_count', 'mileage_grade',
                'seat_color', 'options',
                'new_car_price_ratio', 'ai_price_min', 'ai_price_max',
                'dealer_name', 'dealer_company', 'dealer_location',
                'dealer_phone', 'dealer_description',
            ]);
        });
    }
};
