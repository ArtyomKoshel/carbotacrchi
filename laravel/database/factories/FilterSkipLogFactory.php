<?php

namespace Database\Factories;

use App\Models\FilterSkipLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FilterSkipLog> */
class FilterSkipLogFactory extends Factory
{
    protected $model = FilterSkipLog::class;

    public function definition(): array
    {
        return [
            'source'          => 'encar',
            'source_id'       => (string) $this->faker->unique()->numberBetween(30000000, 49999999),
            'rule_name'       => 'max_mileage',
            'rule_id'         => null,
            'action'          => 'skip',
            'field_name'      => 'mileage',
            'field_value'     => '250000',
            'skipped_at'      => now()->subDays(2),
            'last_skipped_at' => now()->subHour(),
            'skip_count'      => 3,
        ];
    }
}
