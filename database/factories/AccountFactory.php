<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Party;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bypasses AccountService numbering: factory numbers use a 9xxxxx range that real sequences won't reach in tests.
 *
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'party_id' => Party::factory(),
            'account_number' => 'SV-'.fake()->unique()->numerify('9#####'),
            'account_type' => AccountType::Saving,
            'contract_amount' => null,
            'opened_at' => today(config('app.display_timezone')),
            'created_by' => User::factory()->withRole(Role::Manager),
        ];
    }

    public function loan(string $contractTaka = '10000'): static
    {
        return $this->state(fn (array $attributes) => [
            'account_number' => 'LN-'.fake()->unique()->numerify('9#####'),
            'account_type' => AccountType::Loan,
            'contract_amount' => Money::fromTaka($contractTaka),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => ['closed_at' => today(config('app.display_timezone'))]);
    }
}
