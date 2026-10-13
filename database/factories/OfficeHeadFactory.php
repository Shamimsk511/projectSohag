<?php

namespace Database\Factories;

use App\Enums\ActiveStatus;
use App\Enums\TransactionType;
use App\Models\OfficeHead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeHead>
 */
class OfficeHeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'kind' => TransactionType::OfficeIncome,
            'status' => ActiveStatus::Active,
        ];
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => TransactionType::OfficeExpense]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ActiveStatus::Inactive]);
    }
}
