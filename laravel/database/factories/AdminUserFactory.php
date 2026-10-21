<?php

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdminUser> */
class AdminUserFactory extends Factory
{
    public const PASSWORD = 'test-password-1';

    protected $model = AdminUser::class;

    public function definition(): array
    {
        return [
            'username' => 'admin_' . $this->faker->unique()->numberBetween(1000, 999999),
            'password' => self::PASSWORD, // hashed by AdminUser::setPasswordAttribute
            'role'     => 'super',
        ];
    }

    public function limited(): static
    {
        return $this->state(['role' => 'limited']);
    }
}
