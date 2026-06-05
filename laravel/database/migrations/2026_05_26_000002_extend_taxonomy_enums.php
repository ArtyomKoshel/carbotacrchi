<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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

        DB::statement("
            ALTER TABLE taxonomy_rules
            MODIFY COLUMN action ENUM(
                'set_trim',
                'set_generation',
                'strip_tail',
                'replace_model',
                'set_fuel',
                'set_drive_type',
                'set_variant'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE taxonomy_terms
            MODIFY COLUMN term_type ENUM(
                'trim_hint',
                'package_hint',
                'tail_powertrain_token'
            ) NOT NULL
        ");

        DB::statement("
            ALTER TABLE taxonomy_rules
            MODIFY COLUMN action ENUM(
                'set_trim',
                'set_generation',
                'strip_tail',
                'replace_model'
            ) NOT NULL
        ");
    }
};
