<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PortalStatementRequest;
use App\Models\Account;
use App\Models\Party;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Support\PortalSession;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Read-only member pages. Everything is scoped to the party of the unlocked portal session.
 */
class PortalController extends Controller
{
    public function home(ReportService $reports): View
    {
        $party = $this->party();
        $party->load(['accounts' => fn ($accounts) => $accounts->orderBy('closed_at')->orderBy('id')]);

        return view('portal.home', [
            'party' => $party,
            'progress' => $reports->memberAccounts($party->accounts, Carbon::parse(today(config('app.display_timezone'))->toDateString())),
        ]);
    }

    public function statement(PortalStatementRequest $request, Account $account, LedgerService $ledger): View
    {
        abort_unless((int) $account->party_id === PortalSession::partyId(), 404);

        return view('portal.statement', [
            'account' => $account,
            'statement' => $ledger->statement($account, $request->periodFrom(), $request->periodTo()),
        ]);
    }

    public function receipt(Transaction $transaction): View
    {
        abort_unless($transaction->receipt_no !== null && (int) $transaction->party_id === PortalSession::partyId(), 404);
        $transaction->load(['account', 'party', 'fieldOfficer', 'createdBy', 'reversal']);

        return view('portal.receipt', ['transaction' => $transaction]);
    }

    private function party(): Party
    {
        return Party::query()->findOrFail(PortalSession::partyId());
    }
}
