<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filter_skip_log', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);          // encar / kbcha
            $table->string('source_id', 100);      // external lot ID
            $table->string('lot_url', 500)->nullable();
            $table->string('rule_name', 100);      // filter rule name
            $table->unsignedInteger('rule_id')->nullable();
            $table->enum('action', ['skip', 'mark_inactive']);
            $table->string('field_name', 100)->nullable();
            $table->text('field_value')->nullable();
            $table->timestamp('skipped_at')->useCurrent();

            $table->index(['source', 'skipped_at'], 'idx_source_date');
            $table->index('rule_id', 'idx_rule');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_skip_log');
    }
};
