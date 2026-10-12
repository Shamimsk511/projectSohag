{{-- Collection plan summary and the next schedule rows. Expects $account, $upcoming, $date. --}}
@php($plan = $account->collectionPlan)
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title mb-0">{{ __('app.schedules.plan') }}</h3></div>
    <div class="card-body">
        @if ($plan)
            <p class="mb-1 fw-semibold">
                {{ __('app.schedules.plan_summary', [
                    'amount' => $plan->installment_amount->format(),
                    'frequency' => __('app.schedules.frequencies.'.$plan->frequency->value),
                    'from' => $date($plan->starts_on),
                ]) }}
                @if ($plan->ends_on)
                    · {{ __('app.schedules.plan_until', ['date' => $date($plan->ends_on)]) }}
                @endif
            </p>
            @if ($plan->weekdays)
                <p class="mb-0 small text-body-secondary">
                    {{ collect($plan->weekdays)->sort()->map(fn (int $day) => __('app.schedules.weekday_names')[$day])->implode(', ') }}
                </p>
            @endif
        @else
            <p class="mb-0 text-body-secondary">{{ __('app.schedules.no_plan') }}</p>
        @endif
    </div>
    <div class="card-header border-top"><h3 class="card-title mb-0">{{ __('app.schedules.upcoming') }}</h3></div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>{{ __('app.schedules.due_date') }}</th>
                    <th class="text-end">{{ __('app.schedules.expected_amount') }}</th>
                    <th>{{ __('app.schedules.officer') }}</th>
                    <th>{{ __('app.schedules.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($upcoming as $schedule)
                    <tr>
                        <td>{{ $date($schedule->due_date) }}</td>
                        <td class="text-end">{{ $schedule->expected_amount->format() }}</td>
                        <td>{{ $schedule->fieldOfficer?->name ?? __('app.schedules.unassigned') }}</td>
                        <td>{{ __('app.schedules.statuses.'.$schedule->status->value) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-body-secondary py-3">{{ __('app.schedules.no_upcoming') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
