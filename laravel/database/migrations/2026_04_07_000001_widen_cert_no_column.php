<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_inspections', function (Blueprint $table) {
            $table->string('cert_no', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('lot_inspections', function (Blueprint $table) {
            $table->string('cert_no', 20)->nullable()->change();
        });
    }
};
