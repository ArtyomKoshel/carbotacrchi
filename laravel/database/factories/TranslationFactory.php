<?php

namespace Database\Factories;

use App\Models\Translation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Translation> */
class TranslationFactory extends Factory
{
    protected $model = Translation::class;

    public function definition(): array
    {
        $n = $this->faker->unique()->numberBetween(1000, 999999);

        return [
            'category' => 'trim',
            'kr'       => "트림 {$n}",
            'en'       => "Trim {$n}",
            'ru'       => null,
            'source'   => 'ai',
        ];
    }
}
