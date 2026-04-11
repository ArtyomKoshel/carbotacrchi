<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Speeds up: SELECT source, SUM(is_active), COUNT(*), MAX(parsed_at) GROUP BY source
        Schema::table('lots', function (Blueprint $table) {
            $table->index(['source', 'is_active', 'parsed_at'], 'lots_source_active_parsed_idx');
        });

        // Speeds up: SELECT event, COUNT(*) WHERE recorded_at >= NOW()-1day GROUP BY event
        Schema::table('lot_changes', function (Blueprint $table) {
            $table->index(['recorded_at', 'event'], 'lot_changes_recorded_event_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropIndex('lots_source_active_parsed_idx');
        });
        Schema::table('lot_changes', function (Blueprint $table) {
            $table->dropIndex('lot_changes_recorded_event_idx');
        });
    }
};
