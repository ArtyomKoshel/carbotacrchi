<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * lots.price is in the platform's own currency (KRW for Encar, CNY for che168); this says which.
 * A column with a default at the end is instant in MySQL 8+: nothing is rebuilt, every lot
 * stored so far reads as KRW.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $t) {
            $t->char('currency', 3)->default('KRW');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $t) {
            $t->dropColumn('currency');
        });
    }
};
