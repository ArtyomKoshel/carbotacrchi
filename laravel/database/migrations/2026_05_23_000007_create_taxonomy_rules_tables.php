<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_rules', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->default('encar')->index();
            $table->string('make', 80)->nullable()->index();
            $table->string('model_contains', 160)->nullable();
            $table->string('unknown_tail', 191)->nullable()->index();
            $table->enum('action', ['set_trim', 'set_generation', 'strip_tail', 'replace_model']);
            $table->string('action_value', 191)->nullable();
            $table->integer('priority')->default(100)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('hit_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['source', 'is_active', 'priority']);
        });

        Schema::create('taxonomy_rule_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('taxonomy_rules')->cascadeOnDelete();
            $table->string('source', 32)->default('encar')->index();
            $table->string('lot_id', 40)->nullable()->index();
            $table->string('make', 80)->nullable();
            $table->string('model_before', 191)->nullable();
            $table->string('model_after', 191)->nullable();
            $table->string('generation_before', 64)->nullable();
            $table->string('generation_after', 64)->nullable();
            $table->string('trim_before', 191)->nullable();
            $table->string('trim_after', 191)->nullable();
            $table->string('unknown_tail', 191)->nullable();
            $table->boolean('applied')->default(false)->index();
            $table->timestamps();

            $table->index(['source', 'created_at']);
        });

        Schema::create('taxonomy_anomaly_queue', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->default('encar')->index();
            $table->string('make', 80)->nullable()->index();
            $table->string('unknown_tail', 191)->index();
            $table->string('reason', 191)->nullable()->index();
            $table->string('sample_lot_id', 40)->nullable();
            $table->string('sample_model_raw', 191)->nullable();
            $table->json('sample_payload')->nullable();
            $table->unsignedInteger('seen_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->enum('status', ['new', 'rule_created', 'ignored'])->default('new')->index();
            $table->string('suggested_action', 64)->nullable();
            $table->string('suggested_value', 191)->nullable();
            $table->decimal('suggestion_confidence', 5, 2)->nullable();
            $table->foreignId('rule_id')->nullable()->constrained('taxonomy_rules')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source', 'make', 'unknown_tail', 'reason'], 'taxonomy_anomaly_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_anomaly_queue');
        Schema::dropIfExists('taxonomy_rule_hits');
        Schema::dropIfExists('taxonomy_rules');
    }
};
