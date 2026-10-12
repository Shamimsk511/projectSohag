<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Party;
use App\Models\PartyAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartyAssignment>
 */
class PartyAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'party_id' => Party::factory(),
            'field_officer_id' => User::factory()->withRole(Role::FieldOfficer),
            'assigned_from' => today(config('app.display_timezone')),
            'assigned_until' => null,
            'assigned_by' => User::factory()->withRole(Role::Manager),
        ];
    }
}
