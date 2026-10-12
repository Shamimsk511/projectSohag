<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\DailyClosing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyClosing>
 */
class DailyClosingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => today(config('app.display_timezone'))->toDateString(),
            'closed_by' => User::factory()->withRole(Role::Accountant),
        ];
    }
}
