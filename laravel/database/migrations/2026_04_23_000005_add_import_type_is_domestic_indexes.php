<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            // Add indexes for frequently filtered columns
            if (!Schema::hasIndex('lots', ['import_type'])) {
                $table->index('import_type');
            }
            if (!Schema::hasIndex('lots', ['is_domestic'])) {
                $table->index('is_domestic');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropIndex(['import_type']);
            $table->dropIndex(['is_domestic']);
        });
    }
};
