<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE taxonomy_anomaly_queue MODIFY status ENUM('new','rule_created','ignored','ai_reviewed') NOT NULL DEFAULT 'new'");
    }

    public function down(): void
    {
        DB::statement("UPDATE taxonomy_anomaly_queue SET status = 'new' WHERE status = 'ai_reviewed'");
        DB::statement("ALTER TABLE taxonomy_anomaly_queue MODIFY status ENUM('new','rule_created','ignored') NOT NULL DEFAULT 'new'");
    }
};
