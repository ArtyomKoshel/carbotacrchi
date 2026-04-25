<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('parse_filters', function (Blueprint $table) {
            $table->enum('phase', ['pre', 'post'])->default('pre')->after('enabled')
                  ->comment('pre = before enrichment, post = after inspections loaded');
        });
    }

    public function down(): void
    {
        Schema::table('parse_filters', function (Blueprint $table) {
            $table->dropColumn('phase');
        });
    }
};
