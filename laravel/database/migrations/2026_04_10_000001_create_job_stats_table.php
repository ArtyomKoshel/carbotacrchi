<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_id')->index();
            $table->string('source', 32)->index();

            // Counts
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('api_total')->default(0);
            $table->unsignedInteger('new_lots')->default(0);
            $table->unsignedInteger('updated_lots')->default(0);
            $table->unsignedInteger('stale_lots')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedInteger('db_count')->default(0);
            $table->decimal('coverage_pct', 5, 1)->default(0);

            // Timing (seconds)
            $table->decimal('elapsed_s', 10, 1)->default(0);
            $table->decimal('search_time_s', 10, 1)->default(0);
            $table->decimal('enrich_time_s', 10, 1)->default(0);
            $table->decimal('pause_time_s', 10, 1)->default(0);
            $table->decimal('avg_per_lot_s', 8, 3)->default(0);

            // Pages
            $table->unsignedInteger('pages')->default(0);

            // Error breakdown (JSON)
            $table->json('error_types')->nullable();
            $table->json('error_log')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('job_id')->references('id')->on('parse_jobs')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_stats');
    }
};
