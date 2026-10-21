<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Telegram users of the bot (users table). @extends Factory<User> */
class BotUserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'id'         => $this->faker->unique()->numberBetween(1000000, 9999999),
            'username'   => 'picker_' . $this->faker->unique()->numberBetween(1000, 999999),
            'first_name' => 'Тест',
            'last_seen'  => now()->subHour(),
        ];
    }
}
