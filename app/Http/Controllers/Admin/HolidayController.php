<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHolidayRequest;
use App\Models\Holiday;
use App\Services\CollectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class HolidayController extends Controller
{
    public function __construct(private readonly CollectionService $collections) {}

    public function index(): View
    {
        Gate::authorize('viewAny', Holiday::class);

        return view('admin.holidays.index', [
            'holidays' => Holiday::query()->orderByDesc('date')->paginate(50),
            'today' => today(config('app.display_timezone'))->toDateString(),
        ]);
    }

    public function store(StoreHolidayRequest $request): RedirectResponse
    {
        $this->collections->addHoliday($request->holidayDate(), $request->string('name')->trim()->value(), $this->actor());

        return redirect()->route('admin.holidays.index')->with('status', __('app.holidays.added'));
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        Gate::authorize('delete', $holiday);

        $this->collections->removeHoliday($holiday, $this->actor());

        return redirect()->route('admin.holidays.index')->with('status', __('app.holidays.removed'));
    }
}
