<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add model_group and badge to bot_filter_settings so they appear
 * in the cascading filter UI and bot search.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('bot_filter_settings')->pluck('field_name')->toArray();

        $toInsert = [];

        if (! in_array('model_group', $existing, true)) {
            $toInsert[] = [
                'field_name'      => 'model_group',
                'field_label'     => 'Группа моделей',
                'dtype'           => 'string',
                'category'        => 'vehicle',
                'enabled'         => 1,
                'tolerance_type'  => 'none',
                'display_in_card' => 0,
                'sort_order'      => 15,
                'created_at'      => now(),
                'updated_at'      => now(),
            ];
        }

        if (! in_array('badge', $existing, true)) {
            $toInsert[] = [
                'field_name'      => 'badge',
                'field_label'     => 'Двигатель / привод',
                'dtype'           => 'string',
                'category'        => 'specs',
                'enabled'         => 1,
                'tolerance_type'  => 'none',
                'display_in_card' => 0,
                'sort_order'      => 26,
                'created_at'      => now(),
                'updated_at'      => now(),
            ];
        }

        if (! empty($toInsert)) {
            DB::table('bot_filter_settings')->insert($toInsert);
        }
    }

    public function down(): void
    {
        DB::table('bot_filter_settings')
            ->whereIn('field_name', ['model_group', 'badge'])
            ->delete();
    }
};
