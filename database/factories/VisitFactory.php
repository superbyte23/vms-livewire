<?php

namespace Database\Factories;

use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VisitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'visitor_id' => Visitor::factory(),
            'host' => fake()->name(),
            'host_user_id' => null,
            'purpose' => fake()->randomElement(['Meeting', 'Interview', 'Delivery', 'Maintenance', 'Other']),
            'photo' => null,
            'badge_number' => 'V-'.now()->format('Ymd').'-'.fake()->unique()->numerify('###'),
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

    public function scheduled(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'scheduled',
            'badge_number' => null,
            'checked_in_at' => null,
            'checked_out_at' => null,
            'expected_date' => fake()->dateTimeBetween('today', '+2 weeks')->format('Y-m-d'),
            'visit_type' => fake()->randomElement(Visit::VISIT_TYPES),
            'qr_code_token' => Str::random(32),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'cancelled',
            'badge_number' => null,
            'checked_in_at' => null,
            'checked_out_at' => null,
        ]);
    }
}
