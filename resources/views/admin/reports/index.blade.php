@extends('layouts.admin')

@section('subtitle', __('app.reports.hub.title'))
@section('content_header_title', __('app.reports.hub.title'))

@php
    $groups = [
        'daily' => [
            ['admin.reports.daily-collection', 'daily_collection', 'bi-journal-text'],
            ['admin.reports.daily-cash-book', 'cash_book', 'bi-book', 'daily_cash_book'],
            ['admin.reports.cash-reconciliation', 'cash_reconciliation', 'bi-safe'],
        ],
        'monthly' => [
            ['admin.reports.monthly', 'monthly', 'bi-bar-chart-line'],
            ['admin.reports.position', 'position', 'bi-pie-chart'],
        ],
        'loans' => [
            ['admin.reports.outstanding', 'outstanding', 'bi-hourglass-split'],
            ['admin.reports.loan-register', 'loan_register', 'bi-cash-coin'],
            ['admin.reports.missed-partial', 'missed_partial', 'bi-exclamation-triangle'],
        ],
        'savings' => [
            ['admin.reports.withdrawal-register', 'withdrawal_register', 'bi-box-arrow-up'],
        ],
        'officers' => [
            ['admin.reports.officer-performance', 'officer_performance', 'bi-person-check'],
        ],
    ];
@endphp

@section('content_body')
    @foreach ($groups as $group => $tiles)
        <h2 class="h5 mt-2">{{ __('app.reports.hub.groups.'.$group) }}</h2>
        <div class="row g-3 mb-4">
            @foreach ($tiles as $tile)
                @php([$route, $titleKey, $icon] = $tile)
                <div class="col-sm-6 col-xl-4">
                    <a href="{{ route($route) }}" class="card h-100 text-decoration-none">
                        <div class="card-body d-flex gap-3 align-items-start">
                            <i class="bi {{ $icon }} fs-1 text-primary" aria-hidden="true"></i>
                            <div>
                                <div class="fs-5 fw-semibold text-body">{{ __('app.reports.'.$titleKey.'.title') }}</div>
                                <div class="small text-body-secondary">{{ __('app.reports.hub.desc.'.($tile[3] ?? $titleKey)) }}</div>
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endforeach
@stop
