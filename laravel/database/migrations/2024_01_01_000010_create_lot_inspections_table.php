<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_inspections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('lot_id', 100)->unique();
            $table->string('source', 20)->default('carmodoo');

            // Certificate & validity
            $table->string('cert_no', 20)->nullable();
            $table->date('inspection_date')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('report_url', 500)->nullable();

            // Registration
            $table->date('first_registration')->nullable();
            $table->unsignedInteger('inspection_mileage')->nullable();
            $table->unsignedInteger('insurance_fee')->nullable();

            // Condition flags
            $table->boolean('has_accident')->nullable();
            $table->boolean('has_outer_damage')->nullable();
            $table->boolean('has_flood')->nullable();
            $table->boolean('has_fire')->nullable();
            $table->boolean('has_tuning')->nullable();

            // Descriptions
            $table->text('accident_detail')->nullable();
            $table->text('outer_detail')->nullable();

            // Rich data (panels, components, notes)
            $table->json('details')->nullable();

            $table->timestamps();

            $table->foreign('lot_id')->references('id')->on('lots')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_inspections');
    }
};
