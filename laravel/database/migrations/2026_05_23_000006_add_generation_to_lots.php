<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (!Schema::hasColumn('lots', 'generation')) {
                $table->string('generation', 40)->nullable()->after('model_en');
                $table->index('generation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            if (Schema::hasColumn('lots', 'generation')) {
                $table->dropIndex(['generation']);
                $table->dropColumn('generation');
            }
        });
    }
};
