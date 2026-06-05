<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix the tolerance values to match the new sensible defaults:
        // - price:           15% → 10%
        // - mileage:         absolute 10 000 → percentage 10%
        // - engine_volume:   percentage 10% → none  (exact engine match required)
        // - insurance_count: absolute 1     → none  (exact requirement, no fuzziness)
        // - owners_count:    absolute 1     → none  (exact requirement, no fuzziness)
        // - generation:      enable display in card

        $updates = [
            'price' => [
                'tolerance_type'  => 'percentage',
                'tolerance_value' => 0.10,
            ],
            'mileage' => [
                'tolerance_type'  => 'percentage',
                'tolerance_value' => 0.10,
            ],
            'engine_volume' => [
                'tolerance_type'  => 'none',
                'tolerance_value' => null,
            ],
            'insurance_count' => [
                'tolerance_type'  => 'none',
                'tolerance_value' => null,
            ],
            'owners_count' => [
                'tolerance_type'  => 'none',
                'tolerance_value' => null,
            ],
        ];

        foreach ($updates as $field => $values) {
            DB::table('bot_filter_settings')
                ->where('field_name', $field)
                ->update($values);
        }

        // Enable generation display in card if it exists
        DB::table('bot_filter_settings')
            ->where('field_name', 'generation')
            ->update(['enabled' => true, 'display_in_card' => true]);

        // Enable trim display in card
        DB::table('bot_filter_settings')
            ->where('field_name', 'trim')
            ->update(['display_in_card' => true]);
    }

    public function down(): void
    {
        // Revert to old values
        $reverts = [
            'price'           => ['tolerance_type' => 'percentage', 'tolerance_value' => 0.15],
            'mileage'         => ['tolerance_type' => 'absolute',   'tolerance_value' => 10000],
            'engine_volume'   => ['tolerance_type' => 'percentage', 'tolerance_value' => 0.10],
            'insurance_count' => ['tolerance_type' => 'absolute',   'tolerance_value' => 1],
            'owners_count'    => ['tolerance_type' => 'absolute',   'tolerance_value' => 1],
        ];

        foreach ($reverts as $field => $values) {
            DB::table('bot_filter_settings')
                ->where('field_name', $field)
                ->update($values);
        }
    }
};
