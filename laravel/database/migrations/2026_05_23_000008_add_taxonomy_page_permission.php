<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('admin_page_permissions')
            ->where('page_key', 'taxonomy')
            ->exists();

        if (!$exists) {
            DB::table('admin_page_permissions')->insert([
                'page_key' => 'taxonomy',
                'label' => 'Таксономия',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('admin_page_permissions')
            ->where('page_key', 'taxonomy')
            ->delete();
    }
};
