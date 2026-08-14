<?php

namespace Database\Factories;

use App\Models\PreRegistration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PreRegistration> */
class PreRegistrationFactory extends Factory
{
    protected $model = PreRegistration::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'company' => fake()->company(),
            'host' => fake()->name(),
            'host_user_id' => User::factory(),
            'purpose' => fake()->sentence(3),
            'expected_date' => fake()->dateTimeBetween('today', '+2 weeks')->format('Y-m-d'),
            'status' => 'pending',
            'notes' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function used(): static
    {
        return $this->state(fn () => ['status' => 'used']);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }
}
