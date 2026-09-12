<?php

namespace Database\Factories;

use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Visitor> */
class VisitorFactory extends Factory
{
    protected $model = Visitor::class;

    public function definition(): array
    {
        $firstname = fake()->firstName();
        $lastname = fake()->lastName();

        return [
            'name' => Visitor::composeName($firstname, null, $lastname),
            'firstname' => $firstname,
            'middlename' => null,
            'lastname' => $lastname,
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'company' => fake()->company(),
            'address' => fake()->address(),
            'government_id' => fake()->bothify('??-########'),
            'is_flagged' => false,
            'notes' => null,
        ];
    }

    public function configure(): static
    {
        // Keep rows coherent whichever style the caller uses: parts win when
        // present, otherwise an explicit `name` is split into parts.
        return $this->afterMaking(function (Visitor $visitor) {
            $composed = Visitor::composeName($visitor->firstname, $visitor->middlename, $visitor->lastname);

            if ($composed !== '') {
                $visitor->name = $composed;
            } elseif ($visitor->name) {
                $split = Visitor::splitName($visitor->name);
                $visitor->firstname = $split['firstname'];
                $visitor->middlename = $split['middlename'];
                $visitor->lastname = $split['lastname'];
            }
        });
    }

    public function flagged(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_flagged' => true,
            'notes' => 'Flagged visitor — '.fake()->sentence(),
        ]);
    }

    public function withFullName(string $name): static
    {
        $split = Visitor::splitName($name);

        return $this->state([
            'firstname' => $split['firstname'],
            'middlename' => $split['middlename'],
            'lastname' => $split['lastname'],
            'name' => $name,
        ]);
    }

    public function withMiddleName(?string $middlename = null): static
    {
        return $this->state(function (array $attributes) use ($middlename) {
            $middlename ??= fake()->firstName();
            $firstname = $attributes['firstname'] ?? fake()->firstName();
            $lastname = $attributes['lastname'] ?? fake()->lastName();

            return [
                'firstname' => $firstname,
                'middlename' => $middlename,
                'lastname' => $lastname,
                'name' => Visitor::composeName($firstname, $middlename, $lastname),
            ];
        });
    }

    public function withVisit(array $visitAttributes = []): static
    {
        return $this->has(Visit::factory()->state($visitAttributes), 'visits');
    }
}
