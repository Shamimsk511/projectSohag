<?php

namespace App\Http\Controllers\Field;

use App\Enums\ScheduleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Field\StoreCollectionRequest;
use App\Models\CollectionSchedule;
use App\Models\DailyClosing;
use App\Services\LedgerService;
use App\Services\TransactionService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The field officer's day list and the collect-payment form.
 */
class CollectionController extends Controller
{
    /**
     * How far back unpaid rows stay on the officer's list as arrears.
     */
    private const ARREARS_DAYS = 30;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly TransactionService $transactions,
    ) {}

    public function index(): View
    {
        $today = today(config('app.display_timezone'));

        $schedules = $this->ownRows()
            ->dueOn($today)
            ->where('status', '!=', ScheduleStatus::Cancelled)
            ->get()
            ->sortBy(fn (CollectionSchedule $schedule): string => $schedule->party->name)
            ->values();

        $earlier = $this->ownRows()
            ->where('due_date', '>=', $today->copy()->subDays(self::ARREARS_DAYS)->toDateString())
            ->where('due_date', '<', $today->toDateString())
            ->whereIn('status', [ScheduleStatus::Missed, ScheduleStatus::PartiallyPaid])
            ->orderBy('due_date')
            ->get();

        $rows = $schedules->concat($earlier);
        $collected = $this->ledger->collectedForSchedules($rows->modelKeys());
        $remaining = $rows->mapWithKeys(fn (CollectionSchedule $schedule): array => [
            $schedule->id => $this->remaining($schedule, $collected[$schedule->id]),
        ])->all();

        $sum = fn (Money $total, Money $amount): Money => $total->plus($amount);

        return view('field.home', [
            'schedules' => $schedules,
            'earlier' => $earlier,
            'collected' => $collected,
            'remaining' => $remaining,
            'expectedTotal' => $schedules->map->expected_amount->reduce($sum, Money::zero()),
            'collectedTotal' => $schedules->map(fn (CollectionSchedule $schedule): Money => $collected[$schedule->id])->reduce($sum, Money::zero()),
            'remainingTotal' => $schedules->map(fn (CollectionSchedule $schedule): Money => $remaining[$schedule->id])->reduce($sum, Money::zero()),
            'cashInHand' => $this->ledger->cashInHand([$this->actor()->id])[$this->actor()->id],
            'todayClosed' => DailyClosing::isClosed($today),
        ]);
    }

    public function create(CollectionSchedule $schedule): View|RedirectResponse
    {
        $this->authorize('collect', $schedule);

        if (! $schedule->status->isCollectable()) {
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

        return redirect()->route('field.receipts.show', $transaction)->with('status', __('app.field.saved', [
            'amount' => $transaction->amount->format(),
            'party' => $schedule->party->name,
            'receipt' => $transaction->receipt_no,
        ]));
    }

    /**
     * @return Builder<CollectionSchedule>
     */
    private function ownRows(): Builder
    {
        return CollectionSchedule::query()
            ->where('field_officer_id', $this->actor()->id)
            ->with(['party.area', 'account']);
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
