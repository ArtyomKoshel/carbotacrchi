<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (!Schema::hasColumn('lots', 'sell_type')) {
                $table->string('sell_type', 32)->nullable()->after('warranty_text');
                $table->index('sell_type');
            }
            if (!Schema::hasColumn('lots', 'sell_type_raw')) {
                $table->string('sell_type_raw', 255)->nullable()->after('sell_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'sell_type')) {
                $table->dropIndex(['sell_type']);
                $table->dropColumn('sell_type');
            }
            if (Schema::hasColumn('lots', 'sell_type_raw')) {
                $table->dropColumn('sell_type_raw');
            }
        });
    }
};
