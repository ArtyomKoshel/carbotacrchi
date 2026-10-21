<?php

namespace Database\Factories;

use App\Models\ParseFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ParseFilter> */
class ParseFilterFactory extends Factory
{
    protected $model = ParseFilter::class;

    public function definition(): array
    {
        return [
            'name'          => 'rule_' . $this->faker->unique()->numberBetween(1000, 999999),
            'source'        => null,
            'field'         => 'mileage',
            'operator'      => 'gt',
            'value'         => '200000',
            'action'        => 'skip',
            'priority'      => 10,
            'rule_group_id' => null,
            'phase'         => 'pre',
            'enabled'       => true,
            'description'   => null,
        ];
    }
}
