<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bot_filter_settings')) {
            return;
        }

        $baseSort = (int) DB::table('bot_filter_settings')->max('sort_order');

        DB::table('bot_filter_settings')->updateOrInsert(
            ['field_name' => 'lot_url'],
            [
                'field_label' => 'Lot Url',
                'dtype' => 'string',
                'category' => 'links',
                'enabled' => false,
                'tolerance_type' => 'none',
                'tolerance_value' => null,
                'display_in_card' => true,
                'enum_values' => null,
                'description' => 'Ссылка на лот',
                'sort_order' => $baseSort + 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('bot_filter_settings')->updateOrInsert(
            ['field_name' => 'location'],
            [
                'field_label' => 'Location',
                'dtype' => 'string',
                'category' => 'identification',
                'enabled' => false,
                'tolerance_type' => 'none',
                'tolerance_value' => null,
                'display_in_card' => false,
                'enum_values' => null,
                'description' => 'Местоположение лота',
                'sort_order' => $baseSort + 2,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('bot_filter_settings')) {
            return;
        }

        DB::table('bot_filter_settings')
            ->whereIn('field_name', ['lot_url', 'location'])
            ->delete();
    }
};
