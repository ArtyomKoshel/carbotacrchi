<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_changes', function (Blueprint $table) {
            $table->index(['source', 'recorded_at'], 'lot_changes_source_recorded_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lot_changes', function (Blueprint $table) {
            $table->dropIndex('lot_changes_source_recorded_idx');
        });
    }
};
