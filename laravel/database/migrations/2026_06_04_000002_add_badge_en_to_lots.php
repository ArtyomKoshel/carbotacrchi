<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (! Schema::hasColumn('lots', 'badge_en')) {
                $table->string('badge_en', 200)->nullable()->after('badge_group_en');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'badge_en')) {
                $table->dropColumn('badge_en');
            }
        });
    }
};
