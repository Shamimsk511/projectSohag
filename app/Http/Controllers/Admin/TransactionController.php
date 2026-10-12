<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReverseTransactionRequest;
use App\Http\Requests\Admin\StoreTransactionRequest;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\RedirectResponse;

class TransactionController extends Controller
{
    public function __construct(private readonly TransactionService $transactions) {}

    public function store(StoreTransactionRequest $request, Account $account): RedirectResponse
    {
        $transaction = $this->transactions->record($request->transactionData(), $this->actor());

        $status = $transaction->receipt_no
            ? __('app.transactions.recorded_with_receipt', ['receipt' => $transaction->receipt_no])
            : __('app.transactions.recorded');

        return redirect()->route('admin.accounts.show', $account)->with('status', $status);
    }

    public function reverse(ReverseTransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        $this->transactions->reverse($transaction, $request->string('reason')->value(), $this->actor());

        $redirect = $transaction->account_id
            ? redirect()->route('admin.accounts.statement', $transaction->account_id)
            : redirect()->back();

        return $redirect->with('status', __('app.transactions.reversed'));
    }
}
