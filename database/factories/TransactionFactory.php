<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only shortcut for ledger rows. Application code writes transactions through TransactionService.
 *
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'type' => TransactionType::SavingDeposit,
            'amount' => Money::fromTaka('100'),
            'transaction_date' => today(config('app.display_timezone')),
            'created_by' => User::factory()->withRole(Role::Accountant),
        ];
    }

    public function of(Account $account, TransactionType $type, string $taka): static
    {
        return $this->state(fn (array $attributes) => [
            'account_id' => $account->id,
            'party_id' => $account->party_id,
            'type' => $type,
            'amount' => Money::fromTaka($taka),
        ]);
    }

    /**
     * Office income or expense: no account, no party.
     */
    public function office(TransactionType $type, string $taka): static
    {
        return $this->state(fn (array $attributes) => [
            'account_id' => null,
            'type' => $type,
            'amount' => Money::fromTaka($taka),
        ]);
    }

    /**
     * A reversal row cancelling $original.
     */
    public function reversing(Transaction $original): static
    {
        return $this->state(fn (array $attributes) => [
            'account_id' => $original->account_id,
            'type' => TransactionType::Reversal,
            'amount' => $original->amount,
            'reverses_transaction_id' => $original->id,
        ]);
    }
}
