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

        $lotColumns = array_fill_keys(Schema::getColumnListing('lots'), true);

        DB::table('bot_filter_settings')
            ->get(['id', 'field_name'])
            ->each(function ($row) use ($lotColumns) {
                if (!isset($lotColumns[$row->field_name])) {
                    DB::table('bot_filter_settings')->where('id', $row->id)->delete();
                }
            });
    }

    public function down(): void {}
};
