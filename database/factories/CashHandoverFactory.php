<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\CashHandover;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Inserts directly, skipping CashService's cash-in-hand check; use the service in rule tests.
 *
 * @extends Factory<CashHandover>
 */
class CashHandoverFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'field_officer_id' => User::factory()->withRole(Role::FieldOfficer),
            'amount' => Money::fromTaka('100'),
            'handover_date' => today(config('app.display_timezone')),
            'confirmed_by' => User::factory()->withRole(Role::Accountant),
        ];
    }
}
