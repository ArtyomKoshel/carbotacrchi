<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix bot_filter_settings to match actual UI capabilities:
 *
 *  ENABLE:  year, color, seat_count, first_reg_date, listed_at, total_loss_history
 *  DISABLE: registration_date, registration_year_month, model_group, badge (no FIELD_UI handlers)
 *  ADD:     first_reg_date, listed_at, seat_count if missing
 *  FIX:     field_label → English, sort_order → logical grouping
 */
return new class extends Migration
{
    /** Desired final state. sort_order = UI section order. */
    private const STATE = [
        // name                  label                    enabled  display_in_card  dtype   sort
        'source'               => ['Sources',               true,   false,  'enum',    0],
        'make'                 => ['Make / Model / Trim',   true,   true,   'string',  1],
        'model'                => ['Model',                 true,   true,   'string',  2],
        'trim'                 => ['Trim',                  true,   true,   'string',  3],
        'generation'           => ['Generation / Variant',  true,   true,   'string',  4],
        'year'                 => ['Year',                  true,   true,   'int',     5],
        'first_reg_date'       => ['First Registration',    true,   true,   'date',    6],
        'listed_at'            => ['Date Listed',           true,   false,  'date',    7],
        'price'                => ['Price (₩)',             true,   true,   'int',     8],
        'mileage'              => ['Mileage (km)',          true,   true,   'int',     9],
        'engine_volume'        => ['Engine (L)',            true,   true,   'float',  10],
        'seat_count'           => ['Seats',                 true,   false,  'int',    11],
        'fuel'                 => ['Fuel Type',             true,   true,   'enum',   12],
        'transmission'         => ['Transmission',          true,   true,   'enum',   13],
        'body_type'            => ['Body Type',             true,   false,  'enum',   14],
        'drive_type'           => ['Drive Type',            true,   false,  'enum',   15],
        'color'                => ['Color',                 true,   false,  'string', 16],
        'has_accident'         => ['Accident History',      true,   true,   'bool',   17],
        'flood_history'        => ['Flood History',         true,   true,   'bool',   18],
        'total_loss_history'   => ['Total Loss',            true,   false,  'bool',   19],
        'insurance_count'      => ['Insurance Claims',      true,   true,   'int',    20],
        'owners_count'         => ['Owners',                true,   true,   'int',    21],
        'lien_status'          => ['Lien Status',           true,   false,  'enum',   22],
        'seizure_status'       => ['Seizure Status',        true,   false,  'enum',   23],
        'repair_cost'          => ['Repair Cost',           false,  false,  'int',    24],
        'retail_value'         => ['Retail Value',          false,  false,  'int',    25],
        'options'              => ['Options',               true,   true,   'json',   26],
        // Disable fields that have no FIELD_UI handler
        'registration_date'    => ['Registration Date',     false,  false,  'date',   99],
        'registration_year_month'=>['Reg. Year/Month',      false,  false,  'int',    99],
        'model_group'          => ['Model Group',           false,  false,  'string', 99],
        'badge'                => ['Badge',                 false,  true,   'string', 99],
        'sell_type'            => ['Sell Type',             false,  false,  'enum',   99],
        'lot_url'              => ['Lot URL',               false,  true,   'string', 99],
        'location'             => ['Location',              false,  false,  'string', 99],
        'vin'                  => ['VIN',                   false,  false,  'string', 99],
        'seat_color'           => ['Interior Color',        false,  false,  'string', 99],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('bot_filter_settings')) {
            return;
        }

        $existing = DB::table('bot_filter_settings')
            ->pluck('field_name')
            ->flip()
            ->toArray();

        foreach (self::STATE as $name => [$label, $enabled, $displayInCard, $dtype, $sort]) {
            if (isset($existing[$name])) {
                // Update existing row
                DB::table('bot_filter_settings')
                    ->where('field_name', $name)
                    ->update([
                        'field_label'    => $label,
                        'enabled'        => $enabled,
                        'display_in_card'=> $displayInCard,
                        'dtype'          => $dtype,
                        'sort_order'     => $sort,
                        'updated_at'     => now(),
                    ]);
            } else {
                // Insert missing row
                DB::table('bot_filter_settings')->insert([
                    'field_name'     => $name,
                    'field_label'    => $label,
                    'enabled'        => $enabled,
                    'display_in_card'=> $displayInCard,
                    'dtype'          => $dtype,
                    'category'       => $this->category($name),
                    'sort_order'     => $sort,
                    'tolerance_type' => $this->tolerance($name)['type'],
                    'tolerance_value'=> $this->tolerance($name)['value'],
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive — no rollback needed
    }

    private function category(string $name): string
    {
        return match(true) {
            in_array($name, ['source','make','model','trim','generation','model_group','vin','registration_date','registration_year_month']) => 'identity',
            in_array($name, ['year','first_reg_date','listed_at']) => 'dates',
            in_array($name, ['price','retail_value','repair_cost']) => 'price',
            in_array($name, ['mileage','engine_volume','seat_count','fuel','transmission','body_type','drive_type','color','seat_color','badge']) => 'specs',
            in_array($name, ['has_accident','flood_history','total_loss_history','insurance_count','owners_count']) => 'condition',
            in_array($name, ['lien_status','seizure_status','sell_type']) => 'legal',
            default => 'other',
        };
    }

    private function tolerance(string $name): array
    {
        return match($name) {
            'year'        => ['type' => 'absolute',   'value' => 1],
            'price'       => ['type' => 'percentage', 'value' => 0.10],
            'mileage'     => ['type' => 'percentage', 'value' => 0.10],
            'repair_cost' => ['type' => 'percentage', 'value' => 0.20],
            'retail_value'=> ['type' => 'percentage', 'value' => 0.15],
            default       => ['type' => 'none',       'value' => null],
        };
    }
};
