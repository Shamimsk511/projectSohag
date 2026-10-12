<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountStatementRequest;
use App\Http\Requests\Admin\AdjustAccountRequest;
use App\Http\Requests\Admin\CloseAccountRequest;
use App\Http\Requests\Admin\StoreAccountRequest;
use App\Models\Account;
use App\Models\Party;
use App\Models\User;
use App\Services\AccountService;
use App\Services\LedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function create(Party $party): View
    {
        Gate::authorize('view', $party);
        Gate::authorize('create', Account::class);

        return view('admin.accounts.create', ['party' => $party, 'types' => AccountType::cases()]);
    }

    public function store(StoreAccountRequest $request, Party $party): RedirectResponse
    {
        $account = $this->accounts->open(
            $party,
            $request->accountType(),
            $request->contractAmount(),
            $request->openedAt(),
            $this->actor(),
        );

        return redirect()->route('admin.accounts.show', $account)->with('status', __('app.accounts.opened'));
    }

    public function show(Account $account, LedgerService $ledger): View
    {
        Gate::authorize('view', $account);

        $account->load(['party', 'closedBy', 'adjustments.createdBy']);

        return view('admin.accounts.show', [
            'account' => $account,
            'received' => $account->isLoan() ? $ledger->loanReceived($account) : null,
            'remaining' => $ledger->remainingBalance($account),
        ]);
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

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
