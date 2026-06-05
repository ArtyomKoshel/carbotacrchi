<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add make + model scoping columns to taxonomy_terms
        Schema::table('taxonomy_terms', function (Blueprint $table) {
            $table->string('make', 80)->nullable()->after('source')->index();
            $table->string('model', 160)->nullable()->after('make');
        });

        // 2. Add valid_trim to term_type ENUM
        DB::statement("
            ALTER TABLE taxonomy_terms
            MODIFY COLUMN term_type ENUM(
                'trim_hint',
                'package_hint',
                'tail_powertrain_token',
                'engine_family_tokens',
                'gen_non_chassis_token',
                'gen_exclude_token',
                'variant_exclude',
                'special_tag',
                'valid_trim'
            ) NOT NULL
        ");

        // 3. Add badge_contains to taxonomy_rules for badge-level matching
        Schema::table('taxonomy_rules', function (Blueprint $table) {
            $table->string('badge_contains', 191)->nullable()->after('model_contains');
        });
    }

    public function down(): void
    {
        Schema::table('taxonomy_terms', function (Blueprint $table) {
            $table->dropColumn(['make', 'model']);
        });

        DB::statement("
            ALTER TABLE taxonomy_terms
            MODIFY COLUMN term_type ENUM(
                'trim_hint',
                'package_hint',
                'tail_powertrain_token',
                'engine_family_tokens',
                'gen_non_chassis_token',
                'gen_exclude_token',
                'variant_exclude',
                'special_tag'
            ) NOT NULL
        ");

        Schema::table('taxonomy_rules', function (Blueprint $table) {
            $table->dropColumn('badge_contains');
        });
    }
};
