<?php

namespace Database\Factories;

use App\Models\Lot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lot> */
class LotFactory extends Factory
{
    protected $model = Lot::class;

    public function definition(): array
    {
        return [
            'id'             => (string) $this->faker->unique()->numberBetween(30000000, 49999999),
            'source'         => 'encar',
            'make'           => '현대',
            'make_en'        => 'Hyundai',
            'model_group'    => '쏘나타',
            'model_group_en' => 'Sonata',
            'model'          => 'LF 쏘나타',
            'model_en'       => 'Sonata (LF)',
            'badge'          => '2.0 스마트',
            'badge_en'       => '2.0 Smart',
            'trim'           => '프레스티지',
            'trim_en'        => 'Prestige',
            'year'           => 2019,
            'price'          => 21900000,
            'mileage'        => 65000,
            'fuel'           => 'gasoline',
            'transmission'   => 'automatic',
            'body_type'      => 'sedan',
            'has_accident'   => false,
            'vin'            => 'KMH' . $this->faker->unique()->numerify('##############'),
            'plate_number'   => $this->faker->unique()->numerify('##가####'),
            'is_active'      => true,
            'raw_data'       => ['ad_status' => 'ADVERTISE'],
            'parsed_at'      => now()->subHour(),
            'listed_at'      => now()->subDays(5)->toDateString(),
            'created_at'     => now()->subDays(5),
            'updated_at'     => now()->subHour(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
