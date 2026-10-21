<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ingest path from the collector service (docs/adr/0001, 0003).
 *
 * DDL only (no backfill): migrations run before nginx under a 30 s healthcheck.
 */
return new class extends Migration
{
    public function up(): void
    {
        $collectorDb = config('database.connections.collector.database');
        if ($collectorDb !== config('database.connections.mysql.database')) {
            DB::statement("CREATE DATABASE IF NOT EXISTS `{$collectorDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        // Owned by the collector: append-only outbox of CarLot records.
        if (! Schema::connection('collector')->hasTable('ingest_lots')) {
            Schema::connection('collector')->create('ingest_lots', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('run_id')->nullable();
                $t->string('source', 32);
                $t->string('source_id', 100);
                $t->string('contract', 20);
                $t->json('payload');
                $t->char('content_hash', 64);
                $t->timestamp('created_at', 3)->useCurrent();
                $t->index(['source', 'source_id'], 'idx_ingest_lot');
                $t->index('created_at', 'idx_ingest_created');
            });
        }

        // Owned by the core: how far it has read the buffer, and what it refused.
        Schema::create('ingest_offsets', function (Blueprint $t) {
            $t->string('consumer', 40)->primary();
            $t->unsignedBigInteger('last_id')->default(0);
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('ingest_rejections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ingest_id');
            $t->string('source', 32)->nullable();
            $t->string('source_id', 100)->nullable();
            $t->json('errors');
            $t->timestamp('created_at')->useCurrent();
            $t->index('ingest_id', 'idx_rejection_ingest');
            $t->index('created_at', 'idx_rejection_created');
        });

        // Instant on MySQL 8+/9: nullable column at the end, no table rebuild.
        DB::statement('ALTER TABLE lots ADD COLUMN ingest_hash CHAR(64) NULL, ALGORITHM=INSTANT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE lots DROP COLUMN ingest_hash, ALGORITHM=INSTANT');
        Schema::dropIfExists('ingest_rejections');
        Schema::dropIfExists('ingest_offsets');
        Schema::connection('collector')->dropIfExists('ingest_lots');
    }
};
