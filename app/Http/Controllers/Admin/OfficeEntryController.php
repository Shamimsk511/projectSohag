<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OfficeEntryIndexRequest;
use App\Http\Requests\Admin\StoreOfficeEntryRequest;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Services\TransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Office income and expense: entries with no account. Reversal goes through TransactionController.
 */
class OfficeEntryController extends Controller
{
    public function index(OfficeEntryIndexRequest $request, LedgerService $ledger): View
    {
        $from = $request->periodFrom();
        $to = $request->periodTo();

        $entries = Transaction::query()
            ->whereNull('account_id')
            ->whereIn('type', TransactionType::officeTypes())
            ->datedBetween($from, $to)
            ->with(['reversal', 'account'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.office-entries.index', [
            'entries' => $entries,
            'from' => $from,
            'to' => $to,
            'totals' => $ledger->officeTotals($from, $to),
        ]);
    }

    public function store(StoreOfficeEntryRequest $request, TransactionService $transactions): RedirectResponse
    {
        $transactions->record($request->transactionData(), $this->actor());

        return redirect()->route('admin.office-entries.index')->with('status', __('app.transactions.recorded'));
    }
}
