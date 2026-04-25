<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn([
                'dealer_name',
                'dealer_company',
                'dealer_location',
                'dealer_phone',
                'dealer_description',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->string('dealer_name', 200)->nullable()->after('registration_date');
            $table->string('dealer_company', 200)->nullable()->after('dealer_name');
            $table->string('dealer_location', 500)->nullable()->after('dealer_company');
            $table->string('dealer_phone', 50)->nullable()->after('dealer_location');
            $table->text('dealer_description')->nullable()->after('dealer_phone');
        });
    }
};
