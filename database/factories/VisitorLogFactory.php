<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VisitorLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'visitor_id' => Visitor::factory(),
            'host' => fake()->name(),
            'host_user_id' => null,
            'purpose' => fake()->randomElement(['Meeting', 'Interview', 'Delivery', 'Maintenance', 'Other']),
            'photo' => null,
            'badge_number' => 'V-' . now()->format('Ymd') . '-' . fake()->unique()->numerify('###'),
            'qr_code_token' => Str::random(32),
            'status' => 'checked_in',
            'checked_in_at' => fake()->dateTimeThisMonth(),
            'checked_out_at' => null,
            'notes' => null,
        ];
    }

    public function checkedOut(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'checked_out',
            'checked_out_at' => now(),
        ]);
    }
}
