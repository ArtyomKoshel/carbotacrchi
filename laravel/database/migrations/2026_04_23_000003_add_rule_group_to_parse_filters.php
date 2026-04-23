<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parse_filters', function (Blueprint $table) {
            $table->string('rule_group_id', 64)
                  ->nullable()
                  ->after('priority')
                  ->comment('Rules with same group_id use AND logic (all must match). NULL = independent rule.');
            $table->index('rule_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('parse_filters', function (Blueprint $table) {
            $table->dropIndex(['rule_group_id']);
            $table->dropColumn('rule_group_id');
        });
    }
};
