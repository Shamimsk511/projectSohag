<?php

namespace App\Models;

use App\Enums\PlanFrequency;
use App\Support\Money;
use Database\Factories\CollectionPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How much an account is expected to pay and when. CollectionService turns it into schedule rows one
 * day at a time. Written only by CollectionService.
 *
 * @property int $id
 * @property int $account_id
 * @property PlanFrequency $frequency
 * @property Money $installment_amount
 * @property list<int>|null $weekdays
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property int $created_by
 */
#[Fillable(['account_id', 'frequency', 'installment_amount', 'weekdays', 'starts_on', 'ends_on', 'created_by'])]
class CollectionPlan extends Model
{
    /** @use HasFactory<CollectionPlanFactory> */
    use HasFactory;

    /**
     * Default collection days for a daily plan: every day but Friday.
     */
    public const DEFAULT_WEEKDAYS = [Carbon::SATURDAY, Carbon::SUNDAY, Carbon::MONDAY, Carbon::TUESDAY, Carbon::WEDNESDAY, Carbon::THURSDAY];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => PlanFrequency::class,
            'installment_amount' => Money::class,
            'weekdays' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isInRange(Carbon $date): bool
    {
        return $date->gte($this->starts_on) && ($this->ends_on === null || $date->lte($this->ends_on));
    }

    /**
     * Whether the plan falls on $date by its frequency alone, before holidays are applied.
     * Monthly plans starting on the 29th-31st fall on the last day of shorter months.
     */
    public function fallsOn(Carbon $date): bool
    {
        if (! $this->isInRange($date)) {
            return false;
        }

        return match ($this->frequency) {
            PlanFrequency::Daily => in_array($date->dayOfWeek, $this->weekdays ?? [], true),
            PlanFrequency::Weekly => $date->dayOfWeek === $this->starts_on->dayOfWeek,
            PlanFrequency::Monthly => $date->day === min($this->starts_on->day, $date->daysInMonth),
        };
    }

    /**
     * Whether a row is due on $date. Nothing is due on a holiday; daily plans skip it, weekly and
     * monthly plans whose day fell on holidays just before $date are due on $date instead.
     *
     * @param  list<string>  $holidays  Y-m-d
     */
    public function isDueOn(Carbon $date, array $holidays): bool
    {
        if (in_array($date->toDateString(), $holidays, true)) {
            return false;
        }

        if ($this->frequency === PlanFrequency::Daily) {
            return $this->fallsOn($date);
        }

        $candidate = $date->copy();
        do {
            if ($this->fallsOn($candidate)) {
                return true;
            }
            $candidate->subDay();
        } while (in_array($candidate->toDateString(), $holidays, true));

        return false;
    }

    /**
     * The first $count due dates on or after $from, by the generator's rules. Fewer when the plan
     * ends first (or within 40 years).
     *
     * @param  list<string>  $holidays  Y-m-d
     * @return list<Carbon>
     */
    public function projectedDates(Carbon $from, int $count, array $holidays): array
    {
        $date = Carbon::parse($from->max($this->starts_on)->toDateString());
        $until = $this->ends_on?->copy()->addMonth() ?? $date->copy()->addYears(40);
        $dates = [];

        while (count($dates) < $count && $date->lte($until)) {
            if ($this->isDueOn($date, $holidays)) {
                $dates[] = $date->copy();
            }
            $date->addDay();
        }

        return $dates;
    }

    /**
     * Installment times the collection days from start to end, before holidays. Null for an open-ended plan.
     */
    public function estimatedTotal(): ?Money
    {
        if ($this->ends_on === null) {
            return null;
        }

        $days = 0;
        for ($date = $this->starts_on->copy(); $date->lte($this->ends_on); $date->addDay()) {
            $days += $this->fallsOn($date) ? 1 : 0;
        }

        return Money::fromPoisha($this->installment_amount->poisha * $days);
    }
}
