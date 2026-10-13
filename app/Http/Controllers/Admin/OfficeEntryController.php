<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OfficeEntryIndexRequest;
use App\Http\Requests\Admin\StoreClientOfficeIncomeRequest;
use App\Http\Requests\Admin\StoreOfficeEntryRequest;
use App\Models\Account;
use App\Models\OfficeHead;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Services\TransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Office income and expense. Entries may link a client's account (income taken from a client). Reversal goes through TransactionController.
 */
class OfficeEntryController extends Controller
{
    public function index(OfficeEntryIndexRequest $request, LedgerService $ledger): View
    {
        $from = $request->periodFrom();
        $to = $request->periodTo();

        $entries = Transaction::query()
            ->whereIn('type', TransactionType::officeTypes())
            ->datedBetween($from, $to)
            ->with(['reversal', 'account', 'party', 'officeHead'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.office-entries.index', [
            'entries' => $entries,
            'from' => $from,
            'to' => $to,
            'totals' => $ledger->officeTotals($from, $to),
            'headTotals' => $ledger->officeTotalsByHead($from, $to),
            'heads' => OfficeHead::query()->active()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreOfficeEntryRequest $request, TransactionService $transactions): RedirectResponse
    {
        $transactions->record($request->transactionData(), $this->actor());

        return redirect()->route('admin.office-entries.index')->with('status', __('app.transactions.recorded'));
    }

    /**
     * Income taken from a client (fee, form...), linked to the client's account with a receipt.
     */
    public function storeForAccount(StoreClientOfficeIncomeRequest $request, Account $account, TransactionService $transactions): RedirectResponse
    {
        try {
            $transaction = $transactions->record($request->transactionData(), $this->actor());
        } catch (ValidationException $exception) {
            throw $exception->errorBag('officeIncome');
        }

        return redirect()->route('admin.accounts.show', $account)
            ->with('status', __('app.transactions.recorded_with_receipt', ['receipt' => $transaction->receipt_no]));
    }
}
