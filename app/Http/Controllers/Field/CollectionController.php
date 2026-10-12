<?php

namespace App\Http\Controllers\Field;

use App\Enums\ScheduleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Field\StoreCollectionRequest;
use App\Models\CollectionSchedule;
use App\Services\LedgerService;
use App\Services\TransactionService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The field officer's day list and the collect-payment form.
 */
class CollectionController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly TransactionService $transactions,
    ) {}

    public function index(): View
    {
        $schedules = CollectionSchedule::query()
            ->where('field_officer_id', $this->actor()->id)
            ->dueOn(today(config('app.display_timezone')))
            ->where('status', '!=', ScheduleStatus::Cancelled)
            ->with(['party.area', 'account'])
            ->get()
            ->sortBy(fn (CollectionSchedule $schedule): string => $schedule->party->name)
            ->values();

        $collected = $this->ledger->collectedForSchedules($schedules->modelKeys());
        $expectedTotal = $schedules->reduce(fn (Money $sum, CollectionSchedule $schedule): Money => $sum->plus($schedule->expected_amount), Money::zero());
        $collectedTotal = array_reduce($collected, fn (Money $sum, Money $amount): Money => $sum->plus($amount), Money::zero());
        $remainingTotal = $schedules->reduce(
            fn (Money $sum, CollectionSchedule $schedule): Money => $sum->plus($this->remaining($schedule, $collected[$schedule->id])),
            Money::zero(),
        );

        return view('field.home', compact('schedules', 'collected', 'expectedTotal', 'collectedTotal', 'remainingTotal'));
    }

    public function create(CollectionSchedule $schedule): View|RedirectResponse
    {
        $this->authorize('collect', $schedule);

        if (! $this->isOpen($schedule)) {
            return redirect()->route('field.home')->with('status', __('app.transactions.schedule_not_open'));
        }

        $schedule->load(['party', 'account']);
        $collected = $this->ledger->collectedForSchedules([$schedule->id])[$schedule->id];

        return view('field.collections.create', [
            'schedule' => $schedule,
            'collected' => $collected,
            'remaining' => $this->remaining($schedule, $collected),
            'accountRemaining' => $this->ledger->remainingBalance($schedule->account),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreCollectionRequest $request, CollectionSchedule $schedule): RedirectResponse
    {
        $transaction = $this->transactions->record($request->transactionData(), $this->actor());

        return redirect()->route('field.home')->with('status', __('app.field.collected', [
            'amount' => $transaction->amount->format(),
            'party' => $schedule->party->name,
            'receipt' => $transaction->receipt_no,
        ]));
    }

    private function isOpen(CollectionSchedule $schedule): bool
    {
        return in_array($schedule->status, [ScheduleStatus::Pending, ScheduleStatus::PartiallyPaid], true);
    }

    /**
     * Still expected on the row today; never below zero.
     */
    private function remaining(CollectionSchedule $schedule, Money $collected): Money
    {
        $remaining = $schedule->expected_amount->minus($collected);

        return $remaining->isPositive() ? $remaining : Money::zero();
    }
}
