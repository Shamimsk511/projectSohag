<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable/shareable receipt, served from both the field app and the admin statement.
 */
class ReceiptController extends Controller
{
    public function show(Request $request, Transaction $transaction): View
    {
        $this->authorize('viewReceipt', $transaction);

        $transaction->load(['account', 'party', 'fieldOfficer', 'createdBy', 'reversal']);

        return view('receipts.show', [
            'transaction' => $transaction,
            'backUrl' => $request->routeIs('field.*')
                ? route('field.home')
                : route('admin.accounts.statement', (int) $transaction->account_id),
        ]);
    }
}
