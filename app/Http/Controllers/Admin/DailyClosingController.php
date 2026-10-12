<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDailyClosingRequest;
use App\Models\DailyClosing;
use App\Services\CashService;
use App\Support\Numerals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DailyClosingController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', DailyClosing::class);

        return view('admin.daily-closings.index', [
            'closings' => DailyClosing::query()->with('closedBy:id,name')->orderByDesc('date')->paginate(50),
            'today' => today(config('app.display_timezone'))->toDateString(),
        ]);
    }

    public function store(StoreDailyClosingRequest $request, CashService $cash): RedirectResponse
    {
        $closing = $cash->closeDay($request->closingDate(), $this->actor());

        return redirect()->route('admin.daily-closings.index')->with('status', __('app.closings.closed', [
            'date' => Numerals::localize($closing->date->format('d/m/Y')),
        ]));
    }
}
