<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Account;
use App\Models\AccountAdjustment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountAdjustment>
 */
class AccountAdjustmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory()->loan(),
            'old_amount' => Money::fromTaka('10000'),
            'new_amount' => Money::fromTaka('11000'),
            'reason' => fake()->sentence(),
            'created_by' => User::factory()->withRole(Role::Manager),
        ];
    }
}
