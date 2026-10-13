<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Http\Requests\Field\StoreCollectionRequest;
use App\Models\CollectionSchedule;
use App\Models\DailyClosing;
use App\Services\LedgerService;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\Numerals;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
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
        $today = today(config('app.display_timezone'));

        [$schedules, $earlier] = CollectionSchedule::query()
            ->onDayListOf($this->actor(), $today)
            ->with(['party.area', 'account'])
            ->get()
            ->partition(fn (CollectionSchedule $schedule): bool => $schedule->due_date->toDateString() === $today->toDateString());
        $schedules = $schedules->sortBy(fn (CollectionSchedule $schedule): string => $schedule->party->name)->values();
        $earlier = $earlier->sortBy(fn (CollectionSchedule $schedule): string => $schedule->due_date->toDateString())->values();

        $rows = $schedules->concat($earlier);
        $collected = $this->ledger->collectedForSchedules($rows->modelKeys());
        $remaining = $rows->mapWithKeys(fn (CollectionSchedule $schedule): array => [
            $schedule->id => $this->remaining($schedule, $collected[$schedule->id]),
        ])->all();

        $sum = fn (Money $total, Money $amount): Money => $total->plus($amount);
        $expectedTotal = $schedules->map->expected_amount->reduce($sum, Money::zero());
        $collectedTotal = $schedules->map(fn (CollectionSchedule $schedule): Money => $collected[$schedule->id])->reduce($sum, Money::zero());
        $remainingTotal = $schedules->map(fn (CollectionSchedule $schedule): Money => $remaining[$schedule->id])->reduce($sum, Money::zero());
        $cashInHand = $this->ledger->cashInHand([$this->actor()->id])[$this->actor()->id];
        $todayClosed = DailyClosing::isClosed($today);

        // Same rows and figures as the page, formatted on the server, for the offline list (field.js -> IndexedDB).
        $money = fn (Money $amount): array => ['poisha' => $amount->poisha, 'label' => $amount->format()];
        $snapshot = [
            'user_id' => $this->actor()->id,
            'date' => $today->toDateString(),
            'generated_label' => Numerals::localize(now(config('app.display_timezone'))->format('d/m/Y H:i')),
            'today_closed' => $todayClosed,
            'summary' => [
                'visits' => __('app.field.visits', ['count' => Numerals::localize((string) $schedules->count())]),
                'expected' => $expectedTotal->format(),
                'collected' => $collectedTotal->format(),
                'remaining' => $remainingTotal->format(),
                'cash_in_hand' => $cashInHand->format(),
            ],
            'rows' => $rows->map(fn (CollectionSchedule $schedule): array => [
                'schedule_id' => $schedule->id,
                'account_id' => $schedule->account_id,
                'party_id' => $schedule->party_id,
                'party_name' => $schedule->party->name,
                'party_code' => $schedule->party->party_code,
                'party_phone' => $schedule->party->phone,
                'area' => $schedule->party->area->name,
                'account_number' => $schedule->account->account_number,
                'due_date' => $schedule->due_date->toDateString(),
                'due_date_label' => Numerals::localize($schedule->due_date->format('d/m/Y')),
                'status' => $schedule->status->value,
                'status_label' => __('app.schedules.statuses.'.$schedule->status->value),
                'collectable' => $schedule->status->isCollectable(),
                'earlier' => $schedule->due_date->toDateString() < $today->toDateString(),
                'expected' => $money($schedule->expected_amount),
                'collected' => $money($collected[$schedule->id]),
                'remaining' => $money($remaining[$schedule->id]),
            ])->values()->all(),
        ];

        // One card per client: today's rows first, then their earlier unpaid ones. Clients with
        // something still to collect come first; fully paid ones are folded away below.
        [$unpaidClients, $paidClients] = $rows->groupBy('party_id')
            ->sortBy(fn (Collection $partyRows): string => $partyRows->first()->party->name)
            ->values()
            ->partition(fn (Collection $partyRows): bool => $partyRows->contains(
                fn (CollectionSchedule $schedule): bool => $schedule->status->isCollectable() && $remaining[$schedule->id]->isPositive(),
            ));

        // End-of-day line: members fully paid today, everything this officer received today, cash to hand over.
        $todayByParty = $schedules->groupBy('party_id');
        $dayLine = __('app.field.day_line', [
            'paid' => Numerals::localize((string) $todayByParty->filter(
                fn (Collection $partyRows): bool => $partyRows->every(fn (CollectionSchedule $schedule): bool => ! $remaining[$schedule->id]->isPositive()),
            )->count()),
            'total' => Numerals::localize((string) $todayByParty->count()),
            'collected' => Money::fromPoisha((int) $this->ledger->collectionRows(Carbon::parse($today->toDateString()))
                ->where('field_officer_id', $this->actor()->id)
                ->sum('amount'))->format(),
            'cash' => $cashInHand->format(),
        ]);

        return view('field.home', [
            'schedules' => $schedules,
            'unpaidClients' => $unpaidClients->values(),
            'paidClients' => $paidClients->values(),
            'dayLine' => $dayLine,
            'today' => $today->toDateString(),
            'collected' => $collected,
            'remaining' => $remaining,
            'expectedTotal' => $expectedTotal,
            'collectedTotal' => $collectedTotal,
            'remainingTotal' => $remainingTotal,
            'cashInHand' => $cashInHand,
            'todayClosed' => $todayClosed,
            'snapshot' => $snapshot,
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
     * Still expected on the row today; never below zero.
     */
    private function remaining(CollectionSchedule $schedule, Money $collected): Money
    {
        $remaining = $schedule->expected_amount->minus($collected);

        return $remaining->isPositive() ? $remaining : Money::zero();
    }
}
