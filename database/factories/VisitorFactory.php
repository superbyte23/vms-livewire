<?php

namespace Database\Factories;

use App\Models\Visitor;
use App\Models\VisitorLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Visitor> */
class VisitorFactory extends Factory
{
    protected $model = Visitor::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'company' => fake()->company(),
            'valid_id_number' => fake()->bothify('??-########'),
            'is_flagged' => false,
            'notes' => null,
        ];
    }

    public function flagged(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_flagged' => true,
            'notes' => 'Flagged visitor — '.fake()->sentence(),
        ]);
    }

    public function withLog(array $logAttributes = []): static
    {
        return $this->has(VisitorLog::factory()->state($logAttributes), 'logs');
    }
}
