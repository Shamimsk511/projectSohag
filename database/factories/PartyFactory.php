<?php

namespace Database\Factories;

use App\Enums\ActiveStatus;
use App\Models\Area;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '01'.fake()->numberBetween(3, 9).fake()->numerify('########'),
            'address' => fake()->streetAddress(),
            'area_id' => Area::factory(),
            'status' => ActiveStatus::Active,
        ];
    }

    /**
     * Mirror PartyService: the code comes from the id once the row exists.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Party $party): void {
            $party->forceFill(['party_code' => Party::codeFor($party->id)])->save();
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ActiveStatus::Inactive]);
    }
}
