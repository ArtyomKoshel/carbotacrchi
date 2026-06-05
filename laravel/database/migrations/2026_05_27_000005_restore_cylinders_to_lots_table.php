<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('lots', 'cylinders')) {
            Schema::table('lots', function (Blueprint $table) {
                $table->tinyInteger('cylinders')->unsigned()->nullable()->after('engine_volume');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lots', 'cylinders')) {
            Schema::table('lots', function (Blueprint $table) {
                $table->dropColumn('cylinders');
            });
        }
    }
};
