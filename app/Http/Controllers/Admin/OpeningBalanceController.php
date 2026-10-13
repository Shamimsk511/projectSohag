<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOpeningBalanceRequest;
use App\Models\OpeningBalance;
use App\Services\OpeningBalanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Go-live office cash, admin only. Once set it cannot be changed from the UI.
 */
class OpeningBalanceController extends Controller
{
    public function index(OpeningBalanceService $balances): View
    {
        Gate::authorize('viewAny', OpeningBalance::class);

        return view('admin.opening-cash.index', [
            'balance' => $balances->current()?->load('setBy:id,name'),
            'today' => today(config('app.display_timezone'))->toDateString(),
        ]);
    }

    public function store(StoreOpeningBalanceRequest $request, OpeningBalanceService $balances): RedirectResponse
    {
        $balances->set($request->amount(), $request->asOf(), $request->input('note'), $this->actor());

        return redirect()->route('admin.opening-cash.index')->with('status', __('app.opening_cash.saved'));
    }
}
