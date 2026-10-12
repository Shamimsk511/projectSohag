<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountStatementRequest;
use App\Http\Requests\Admin\AdjustAccountRequest;
use App\Http\Requests\Admin\CloseAccountRequest;
use App\Http\Requests\Admin\StoreAccountRequest;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\Party;
use App\Models\Transaction;
use App\Services\AccountService;
use App\Services\LedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccountController extends Controller
{
    /** Latest statement lines shown on the account page; the statement page has the rest. */
    private const int RECENT_LINES = 5;

    public function __construct(private readonly AccountService $accounts) {}

    public function create(Party $party): View
    {
        Gate::authorize('view', $party);
        Gate::authorize('create', Account::class);

        return view('admin.accounts.create', ['party' => $party, 'types' => AccountType::cases()]);
    }

    public function store(StoreAccountRequest $request, Party $party): RedirectResponse
    {
        $account = $request->accountType() === AccountType::Loan
            ? $this->accounts->openLoan($party, $request->principalAmount(), $request->agreedExtra(), $request->paymentMethod(), $request->openedAt(), $this->actor())
            : $this->accounts->openSaving($party, $request->agreedAmount(), $request->openedAt(), $this->actor());

        return redirect()->route('admin.accounts.show', $account)->with('status', __('app.accounts.opened'));
    }

    public function show(Account $account, LedgerService $ledger): View
    {
        Gate::authorize('view', $account);

        $account->load(['party', 'closedBy', 'adjustments.createdBy', 'collectionPlan']);
        $today = today(config('app.display_timezone'))->toDateString();

        return view('admin.accounts.show', [
            'account' => $account,
            'received' => $account->isLoan() ? $ledger->loanReceived($account) : $ledger->savingCredited($account),
            'agreedExtra' => $account->isLoan() ? $ledger->agreedExtra($account) : null,
            'entryTypes' => $this->entryTypes($account, $ledger),
            'remaining' => $ledger->remainingBalance($account),
            'canPost' => Gate::allows('create', [Transaction::class, $account]),
            'canManage' => Gate::allows('update', $account),
            'canSchedule' => Gate::allows('create', CollectionSchedule::class),
            'upcoming' => $account->schedules()->with('fieldOfficer')
                ->where(fn ($query) => $query->where('due_date', '>=', $today)
                    ->orWhereIn('status', [ScheduleStatus::Missed, ScheduleStatus::PartiallyPaid]))
                ->orderBy('due_date')->limit(10)->get(),
            'recent' => array_slice(array_reverse($ledger->statement($account)->lines), 0, self::RECENT_LINES),
        ]);
    }

    /**
     * Entry types worth offering now: the opening balance only on an empty saving account, the
     * maturity credit only once an agreed amount is set. TransactionService enforces both anyway.
     *
     * @return list<TransactionType>
     */
    private function entryTypes(Account $account, LedgerService $ledger): array
    {
        $hasEntries = $ledger->hasEntries($account);

        return array_values(array_filter(
            $account->account_type->entryTypes(),
            fn (TransactionType $type): bool => match ($type) {
                TransactionType::SavingOpeningBalance => ! $hasEntries,
                TransactionType::SavingMaturityCredit => $account->contract_amount !== null,
                default => true,
            },
        ));
    }

    public function statement(AccountStatementRequest $request, Account $account, LedgerService $ledger): View
    {
        $account->load('party');

        return view('admin.accounts.statement', [
            'account' => $account,
            'statement' => $ledger->statement($account, $request->periodFrom(), $request->periodTo()),
        ]);
    }

    public function adjust(AdjustAccountRequest $request, Account $account): RedirectResponse
    {
        $this->accounts->adjustContractAmount($account, $request->contractAmount(), $request->string('reason')->value(), $this->actor());

        return redirect()->route('admin.accounts.show', $account)->with('status', __('app.accounts.adjusted'));
    }

    public function close(CloseAccountRequest $request, Account $account): RedirectResponse
    {
        $this->accounts->close($account, $request->validated('close_reason'), $this->actor());

        return redirect()->route('admin.accounts.show', $account)->with('status', __('app.accounts.closed_message'));
    }
}
