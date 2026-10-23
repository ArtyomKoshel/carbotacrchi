<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What ingest:consume did with each collector run's records (new, updated, unchanged,
 * filtered by rules, refused by the contract): the collector cannot tell a new lot from
 * an update, only the core can. Shown on the run in the admin and in Telegram reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_run_stats', function (Blueprint $t) {
            $t->unsignedBigInteger('run_id')->primary();
            $t->string('source', 32);
            $t->unsignedInteger('inserted')->default(0);
            $t->unsignedInteger('updated')->default(0);
            $t->unsignedInteger('unchanged')->default(0);
            $t->unsignedInteger('filtered')->default(0);
            $t->unsignedInteger('rejected')->default(0);
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_run_stats');
    }
};
