<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE taxonomy_rules
            MODIFY COLUMN action ENUM(
                'set_trim',
                'set_generation',
                'strip_tail',
                'replace_model',
                'set_fuel',
                'set_drive_type',
                'set_variant',
                'set_package'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE taxonomy_rules SET action = 'set_trim' WHERE action = 'set_package'");
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
};
