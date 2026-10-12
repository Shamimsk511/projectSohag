@extends('layouts.admin')

@section('subtitle', __('app.dashboard'))
@section('content_header_title', __('app.dashboard'))

@php
    use App\Support\Numerals;

    $money = fn ($amount) => $amount->format();
    $dayValue = $day->toDateString();
@endphp

@section('content_body')
    <form method="GET" action="{{ route('admin.dashboard') }}" class="row g-2 align-items-end mb-3">
        <div class="col-auto">
            <x-form.input name="date" type="date" :label="__('app.dash.day')" :value="$dayValue" />
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary">{{ __('app.dash.show') }}</button>
        </div>
        @can('viewAny', \App\Models\CollectionSchedule::class)
            <div class="col-auto ms-auto">
                <a class="btn btn-outline-secondary" href="{{ route('admin.schedules.index', ['date' => $dayValue]) }}">{{ __('app.dash.view_list') }}</a>
            </div>
        @endcan
    </form>

    <div class="row g-3 mb-3">
        @foreach ([
            'expected' => $figures['expected'],
            'collected' => $figures['collected'],
            'received_today' => $figures['received_today'],
        ] as $key => $amount)
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <div class="text-body-secondary small">{{ __('app.dash.'.$key) }}</div>
                    <div class="fs-3 fw-semibold">{{ $money($amount) }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        @foreach (\App\Enums\ScheduleStatus::cases() as $status)
            @continue($status === \App\Enums\ScheduleStatus::Cancelled)
            <span class="badge text-bg-light border fs-6 fw-normal">
                {{ __('app.schedules.statuses.'.$status->value) }}:
                {{ Numerals::localize((string) ($figures['counts'][$status->value] ?? 0)) }}
            </span>
        @endforeach
    </div>

    <h2 class="h5">{{ __('app.dash.standing') }}</h2>
    <div class="row g-3 mb-4">
        @foreach ([
            'loan_outstanding' => $figures['loan_outstanding'],
            'saving_balance' => $figures['saving_balance'],
            'cash_in_hand' => $figures['cash_in_hand'],
        ] as $key => $amount)
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <div class="text-body-secondary small">{{ __('app.dash.'.$key) }}</div>
                    <div class="fs-4 fw-semibold">{{ $money($amount) }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title mb-0">{{ __('app.dash.officers') }}</h3></div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.dash.officer') }}</th>
                        <th class="text-end">{{ __('app.dash.rows') }}</th>
                        <th class="text-end">{{ __('app.dash.paid_rows') }}</th>
                        <th class="text-end">{{ __('app.dash.expected') }}</th>
                        <th class="text-end">{{ __('app.dash.collected') }}</th>
                        <th class="text-end">{{ __('app.dash.rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($figures['officers'] as $line)
                        <tr>
                            <td>{{ $line['officer']->name }}</td>
                            <td class="text-end">{{ Numerals::localize((string) $line['rows']) }}</td>
                            <td class="text-end">{{ Numerals::localize((string) $line['paid']) }}</td>
                            <td class="text-end text-nowrap">{{ $money($line['expected']) }}</td>
                            <td class="text-end text-nowrap">{{ $money($line['collected']) }}</td>
                            <td class="text-end">{{ Numerals::localize((string) $line['rate']) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-body-secondary py-4">{{ __('app.dash.no_rows') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@stop
