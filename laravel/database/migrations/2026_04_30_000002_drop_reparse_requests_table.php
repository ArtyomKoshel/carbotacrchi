<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the legacy reparse_requests table.
 *
 * Reparse functionality is now handled by the parse_jobs pipeline
 * (type='reparse' + target_lot_ids). The reparse_worker.py has been
 * merged into job_worker.py which polls parse_jobs for all job types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('reparse_requests');
    }

    public function down(): void
    {
        Schema::create('reparse_requests', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('lot_id');
            $table->enum('status', ['pending', 'running', 'done', 'error'])->default('pending');
            $table->text('result')->nullable();
            $table->timestamps();
        });
    }
};
