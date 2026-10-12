<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelScheduleRequest;
use App\Http\Requests\Admin\ReassignScheduleRequest;
use App\Http\Requests\Admin\ScheduleIndexRequest;
use App\Http\Requests\Admin\StoreScheduleRequest;
use App\Http\Requests\Admin\UpdateScheduleRequest;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\User;
use App\Services\CollectionService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function __construct(private readonly CollectionService $collections) {}

    public function index(ScheduleIndexRequest $request): View
    {
        $day = $request->day();
        $officer = $request->officer();
        $query = $this->filtered($request);

        return view('admin.schedules.index', [
            'schedules' => (clone $query)->with(['party', 'account', 'fieldOfficer'])
                ->orderBy('field_officer_id')->orderBy('party_id')->paginate(100)->withQueryString(),
            'total' => Money::fromPoisha((int) (clone $query)->sum('expected_amount')),
            'day' => $day,
            'officer' => $officer,
            'status' => $request->status(),
            'officers' => User::role(Role::FieldOfficer->value)->orderBy('name')->get(['id', 'name']),
            'activeOfficers' => User::query()->activeFieldOfficers()->orderBy('name')->get(['id', 'name']),
            'canManage' => Gate::allows('create', CollectionSchedule::class),
            'isPast' => $day->toDateString() < today(config('app.display_timezone'))->toDateString(),
        ]);
    }

    /**
     * Printable day sheet: every row matching the list filters, no paging, with blank columns to fill by hand.
     */
    public function print(ScheduleIndexRequest $request): View
    {
        $officer = $request->officer();
        $schedules = $this->filtered($request)->with(['party.area', 'account', 'fieldOfficer'])
            ->orderBy('field_officer_id')->orderBy('party_id')->get();

        return view('admin.schedules.print', [
            'schedules' => $schedules,
            'total' => $schedules->map->expected_amount->reduce(fn (Money $sum, Money $amount): Money => $sum->plus($amount), Money::zero()),
            'day' => $request->day(),
            'officerName' => match (true) {
                $officer === ScheduleIndexRequest::UNASSIGNED => __('app.schedules.unassigned'),
                is_int($officer) => User::query()->whereKey($officer)->value('name'),
                default => __('app.schedules.all'),
            },
            'status' => $request->status(),
            'showOfficer' => ! is_int($officer),
        ]);
    }

    public function store(StoreScheduleRequest $request, Account $account): RedirectResponse
    {
        $this->collections->addSchedule($account, $request->dueDate(), $request->expected(), $request->notes(), $this->actor());

        return redirect()->route('admin.accounts.show', $account)->with('status', __('app.schedules.added'));
    }

    public function edit(CollectionSchedule $schedule): View
    {
        Gate::authorize('update', $schedule);

        return view('admin.schedules.edit', ['schedule' => $schedule->load(['party', 'account', 'fieldOfficer'])]);
    }

    public function update(UpdateScheduleRequest $request, CollectionSchedule $schedule): RedirectResponse
    {
        $this->collections->updateSchedule($schedule, $request->dueDate(), $request->expected(), $request->notes(), $this->actor());

        return redirect()->route('admin.accounts.show', $schedule->account_id)->with('status', __('app.schedules.updated'));
    }

    public function cancel(CancelScheduleRequest $request, CollectionSchedule $schedule): RedirectResponse
    {
        $this->collections->cancelSchedule($schedule, $request->string('reason')->trim()->value(), $this->actor());

        return redirect()->route('admin.accounts.show', $schedule->account_id)->with('status', __('app.schedules.cancelled'));
    }

    public function reassign(ReassignScheduleRequest $request): RedirectResponse
    {
        $moved = $this->collections->reassignDay($request->day(), $request->fromOfficer(), $request->toOfficer(), $this->actor());

        return redirect()->route('admin.schedules.index', [
            'date' => $request->day()->toDateString(),
            'officer' => $request->toOfficer()->id,
        ])->with('status', __('app.schedules.reassigned', ['count' => $moved]));
    }

    /**
     * @return Builder<CollectionSchedule>
     */
    private function filtered(ScheduleIndexRequest $request): Builder
    {
        $officer = $request->officer();

        return CollectionSchedule::query()
            ->dueOn($request->day())
            ->when($officer === ScheduleIndexRequest::UNASSIGNED, fn ($query) => $query->whereNull('field_officer_id'))
            ->when(is_int($officer), fn ($query) => $query->where('field_officer_id', $officer))
            ->when($request->status(), fn ($query, ScheduleStatus $status) => $query->where('status', $status));
    }
}
