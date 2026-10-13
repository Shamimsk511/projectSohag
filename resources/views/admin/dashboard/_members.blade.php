{{-- Schedule rows of one day as member lines. Expects $lines (schedule, collected, remaining) and $showOfficer. --}}
@php
    $badge = [
        'pending' => 'text-bg-secondary',
        'partially_paid' => 'text-bg-warning',
        'paid' => 'text-bg-success',
        'missed' => 'text-bg-danger',
    ];
@endphp
<table class="table table-sm align-middle mb-0">
    <thead>
        <tr>
            <th>{{ __('app.dash.member') }}</th>
            <th>{{ __('app.dash.phone') }}</th>
            @if ($showOfficer)
                <th>{{ __('app.dash.officer') }}</th>
            @endif
            <th class="text-end">{{ __('app.dash.due') }}</th>
            <th class="text-end">{{ __('app.dash.paid') }}</th>
            <th class="text-end">{{ __('app.dash.remaining') }}</th>
            <th>{{ __('app.dash.status') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            @php($schedule = $line['schedule'])
            <tr>
                <td>
                    <a href="{{ route('admin.accounts.show', $schedule->account_id) }}">{{ $schedule->party->name }}</a>
                    <span class="small text-body-secondary">{{ $schedule->account->account_number }}</span>
                </td>
                <td class="text-nowrap">
                    @if ($schedule->party->phone)
                        <a href="tel:{{ $schedule->party->phone }}"><i class="bi bi-telephone" aria-hidden="true"></i> {{ \App\Support\Numerals::localize($schedule->party->phone) }}</a>
                    @endif
                    <x-whatsapp.remind :schedule="$schedule" :remaining="$line['remaining']" :badge="$reminded[$schedule->id] ?? null" class="btn-sm" />
                </td>
                @if ($showOfficer)
                    <td>{{ $schedule->fieldOfficer?->name ?? __('app.schedules.unassigned') }}</td>
                @endif
                <td class="text-end text-nowrap">{{ $schedule->expected_amount->format() }}</td>
                <td class="text-end text-nowrap">{{ $line['collected']->format() }}</td>
                <td @class(['text-end text-nowrap fw-semibold', 'text-danger' => $line['remaining']->isPositive()])>{{ $line['remaining']->format() }}</td>
                <td><span class="badge {{ $badge[$schedule->status->value] ?? 'text-bg-light' }}">{{ __('app.schedules.statuses.'.$schedule->status->value) }}</span></td>
            </tr>
        @endforeach
    </tbody>
</table>
