<?php

namespace App\Support;

use App\Enums\PlanFrequency;
use App\Models\CollectionPlan;
use Illuminate\Support\Carbon;

/**
 * A collection plan as entered on a form, before CollectionService::savePlan() stores it.
 */
final readonly class CollectionPlanData
{
    /**
     * @param  list<int>|null  $weekdays  collection days for a daily plan (0 = Sunday .. 6 = Saturday)
     */
    public function __construct(
        public PlanFrequency $frequency,
        public Money $installment,
        public ?array $weekdays,
        public Carbon $startsOn,
    ) {}

    /**
     * Unsaved plan, for projecting dates before anything is stored.
     */
    public function draft(): CollectionPlan
    {
        return new CollectionPlan([
            'frequency' => $this->frequency,
            'installment_amount' => $this->installment,
            'weekdays' => $this->weekdays,
            'starts_on' => $this->startsOn->toDateString(),
        ]);
    }
}
