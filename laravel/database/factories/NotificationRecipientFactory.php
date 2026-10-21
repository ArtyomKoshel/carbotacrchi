<?php

namespace Database\Factories;

use App\Models\NotificationRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationRecipient> */
class NotificationRecipientFactory extends Factory
{
    protected $model = NotificationRecipient::class;

    public function definition(): array
    {
        return [
            'chat_id' => (string) $this->faker->unique()->numberBetween(100000000, 999999999),
            'label'   => 'test',
            'user_id' => null,
            'enabled' => true,
            'types'   => NotificationRecipient::defaultTypes(),
        ];
    }
}
