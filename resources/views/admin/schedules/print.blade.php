@extends('layouts.field')

@section('title', __('app.schedules.print_title'))

@section('hide_logout', true)

@section('content')
    @php($localize = fn (string $value): string => \App\Support\Numerals::localize($value))

    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ config('app.name') }} - {{ __('app.schedules.print_title') }}</h1>
            <div>
                {{ __('app.schedules.due_date') }}: <strong>{{ $localize($day->format('d/m/Y')) }}</strong>
                · {{ __('app.schedules.officer') }}: <strong>{{ $officerName }}</strong>
                @if ($status)
                    · {{ __('app.schedules.status') }}: <strong>{{ __('app.schedules.statuses.'.$status->value) }}</strong>
                @endif
            </div>
            <div class="small text-body-secondary">
                {{ __('app.schedules.row_count', ['count' => $localize((string) $schedules->count()), 'amount' => $total->format()]) }}
            </div>
        </div>
        <button type="button" class="btn btn-primary btn-lg js-print d-print-none">
            <i class="bi bi-printer" aria-hidden="true"></i> {{ __('app.receipts.print') }}
        </button>
    </div>

    <table class="table table-bordered table-sm align-middle schedule-sheet">
        <thead>
            <tr>
                <th>#</th>
                <th>{{ __('app.schedules.party') }}</th>
                <th>{{ __('app.schedules.phone') }}</th>
                <th>{{ __('app.schedules.area') }}</th>
                <th>{{ __('app.schedules.account') }}</th>
                @if ($showOfficer)
                    <th>{{ __('app.schedules.officer') }}</th>
                @endif
                <th class="text-end">{{ __('app.schedules.expected_amount') }}</th>
                <th>{{ __('app.schedules.status') }}</th>
                <th class="sheet-blank">{{ __('app.schedules.print_collected') }}</th>
                <th class="sheet-blank">{{ __('app.schedules.print_signature') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($schedules as $schedule)
                <tr>
                    <td>{{ $localize((string) $loop->iteration) }}</td>
                    <td>{{ $schedule->party->name }}<div class="small">{{ $schedule->party->party_code }}</div></td>
                    <td class="text-nowrap">{{ $schedule->party->phone }}</td>
                    <td>{{ $schedule->party->area->name }}</td>
                    <td class="text-nowrap">{{ $schedule->account->account_number }}</td>
                    @if ($showOfficer)
                        <td>{{ $schedule->fieldOfficer?->name ?? __('app.schedules.unassigned') }}</td>
                    @endif
                    <td class="text-end text-nowrap">{{ $schedule->expected_amount->format() }}</td>
                    <td>{{ __('app.schedules.statuses.'.$schedule->status->value) }}</td>
                    <td></td>
                    <td></td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center text-body-secondary py-4">{{ __('app.schedules.empty') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
