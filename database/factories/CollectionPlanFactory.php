<?php

namespace Database\Factories;

use App\Enums\PlanFrequency;
use App\Enums\Role;
use App\Models\Account;
use App\Models\CollectionPlan;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionPlan>
 */
class CollectionPlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'frequency' => PlanFrequency::Daily,
            'installment_amount' => Money::fromTaka('100'),
            'weekdays' => CollectionPlan::DEFAULT_WEEKDAYS,
            'starts_on' => today(config('app.display_timezone')),
            'ends_on' => null,
            'created_by' => User::factory()->withRole(Role::Manager),
        ];
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => ['frequency' => PlanFrequency::Weekly, 'weekdays' => null]);
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => ['frequency' => PlanFrequency::Monthly, 'weekdays' => null]);
    }
}
