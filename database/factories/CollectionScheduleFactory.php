<?php

namespace Database\Factories;

use App\Enums\ScheduleStatus;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionSchedule>
 */
class CollectionScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'party_id' => fn (array $attributes) => Account::findOrFail($attributes['account_id'])->party_id,
            'field_officer_id' => null,
            'due_date' => today(config('app.display_timezone')),
            'expected_amount' => Money::fromTaka('100'),
            'status' => ScheduleStatus::Pending,
        ];
    }
}
