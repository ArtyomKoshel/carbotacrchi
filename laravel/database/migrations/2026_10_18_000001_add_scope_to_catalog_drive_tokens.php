<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scope catalog_drive_tokens to a make / model group.
 *
 * "2WD" means FWD for most Korean cars, but RWD on rear-drive platforms
 * (all Genesis, Rexton, Stinger, Mohave, Colorado, E-GMP EVs). The global
 * 2WD → fwd row put ~690 such lots on fwd. Scoped rows (make_kr / model_group_kr
 * set) win over global rows (both NULL) in Step 4b of lots:normalize-from-catalog.
 *
 * model_group_kr NULL with make_kr set = every model of that make.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_drive_tokens', 'make_kr')) {
            DB::statement('
                ALTER TABLE catalog_drive_tokens
                DROP PRIMARY KEY,
                ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST,
                ADD COLUMN make_kr VARCHAR(100) NULL AFTER drive_type,
                ADD COLUMN model_group_kr VARCHAR(100) NULL AFTER make_kr,
                ADD UNIQUE INDEX catalog_drive_tokens_scope_unique (token, make_kr, model_group_kr)
            ');
        }

        $now  = now();
        $rows = [
            ['제네시스',          null],
            ['KG모빌리티(쌍용)',  '렉스턴'],
            ['기아',              '스팅어'],
            ['기아',              '모하비'],
            ['기아',              'EV6'],
            ['기아',              'EV9'],
            ['쉐보레(GM대우)',    '콜로라도'],
            ['쉐보레',            '콜로라도'],
            ['현대',              '아이오닉5'],
            ['현대',              '아이오닉6'],
            ['현대',              '아이오닉9'],
        ];

        foreach ($rows as [$make, $modelGroup]) {
            $exists = DB::table('catalog_drive_tokens')
                ->where('token', '2WD')
                ->where('make_kr', $make)
                ->where(fn ($q) => $modelGroup === null
                    ? $q->whereNull('model_group_kr')
                    : $q->where('model_group_kr', $modelGroup))
                ->exists();

            if (! $exists) {
                DB::table('catalog_drive_tokens')->insert([
                    'token'          => '2WD',
                    'drive_type'     => 'rwd',
                    'make_kr'        => $make,
                    'model_group_kr' => $modelGroup,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('catalog_drive_tokens')->whereNotNull('make_kr')->delete();

        DB::statement('
            ALTER TABLE catalog_drive_tokens
            DROP INDEX catalog_drive_tokens_scope_unique,
            DROP COLUMN model_group_kr,
            DROP COLUMN make_kr,
            DROP PRIMARY KEY,
            DROP COLUMN id,
            ADD PRIMARY KEY (token)
        ');
    }
};
