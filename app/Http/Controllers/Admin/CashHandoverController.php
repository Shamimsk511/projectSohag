<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CashHandoverMode;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCashHandoverRequest;
use App\Models\CashHandover;
use App\Models\User;
use App\Services\CashService;
use App\Services\LedgerService;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Field officers' cash in hand and the cash they hand to the office.
 */
class CashHandoverController extends Controller
{
    public function index(LedgerService $ledger, SettingService $settings): View
    {
        Gate::authorize('viewAny', CashHandover::class);

        $officers = User::role(Role::FieldOfficer->value)->orderBy('name')->get(['id', 'name']);

        return view('admin.cash-handovers.index', [
            'officers' => $officers,
            'inHand' => $ledger->cashInHand($officers->modelKeys()),
            'handovers' => CashHandover::query()
                ->with(['fieldOfficer:id,name', 'confirmedBy:id,name'])
                ->orderByDesc('handover_date')
                ->orderByDesc('id')
                ->paginate(50),
            'automatic' => $settings->cashHandoverMode() === CashHandoverMode::Automatic,
            'today' => today(config('app.display_timezone'))->toDateString(),
        ]);
    }

    public function store(StoreCashHandoverRequest $request, CashService $cash): RedirectResponse
    {
        $officer = $request->officer();
        $handover = $cash->recordHandover($officer, $request->amount(), $request->handoverDate(), $request->note(), $this->actor());

        return redirect()->route('admin.cash-handovers.index')->with('status', __('app.cash.recorded', [
            'amount' => $handover->amount->format(),
            'officer' => $officer->name,
        ]));
    }
}
