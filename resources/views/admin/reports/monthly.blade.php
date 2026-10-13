@extends('layouts.admin')

@section('subtitle', __('app.reports.monthly.title'))
@section('content_header_title', __('app.reports.monthly.title'))

@php
    use App\Support\Numerals;

    $chart = [
        'categories' => array_map(fn (string $date): string => Numerals::localize(substr($date, 8)), array_keys($report['daily'])),
        'series' => array_values(array_map(fn ($money): float => $money->poisha / 100, $report['daily'])),
        'labels' => ['series' => __('app.reports.monthly.chart_series')],
    ];
@endphp

@section('content_body')
    <form method="GET" action="{{ route('admin.reports.monthly') }}" class="card mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label for="month" class="form-label">{{ __('app.reports.monthly.month') }}</label>
                <input type="month" id="month" name="month" value="{{ $month->format('Y-m') }}" @class(['form-control', 'is-invalid' => $errors->has('month')])>
            </div>
            <div class="col-sm-6 col-lg-3">
                <button type="submit" class="btn btn-primary w-100">{{ __('app.reports.monthly.show') }}</button>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        @foreach (['opening', 'money_in', 'money_out', 'closing'] as $key)
            <div class="col-sm-6 col-lg-3">
                <div class="card h-100"><div class="card-body">
                    <div class="text-body-secondary small">{{ __('app.reports.monthly.'.($key === 'opening' || $key === 'closing' ? $key.'_cash' : $key)) }}</div>
                    <div class="fs-4 fw-semibold">{{ $report[$key]->format() }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-3">
        @foreach (['received', 'paid_out', 'office_net'] as $key)
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <div class="text-body-secondary small">{{ __('app.reports.monthly.'.$key) }}</div>
                    <div class="fs-3 fw-semibold">{{ $report[$key]->format() }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title mb-0">{{ __('app.reports.monthly.daily') }}</h3></div>
        <div class="card-body">
            <div id="daily-chart"></div>
            <script type="application/json" id="daily-chart-data">@json($chart)</script>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.reports.monthly.schedule') }}</h3></div>
                <table class="table align-middle mb-0">
                    <tbody>
                        <tr><td>{{ __('app.reports.monthly.expected') }}</td><td class="text-end text-nowrap">{{ $report['expected']->format() }}</td></tr>
                        <tr><td>{{ __('app.reports.monthly.collected') }}</td><td class="text-end text-nowrap">{{ $report['collected']->format() }}</td></tr>
                        <tr><td>{{ __('app.reports.monthly.rate') }}</td><td class="text-end">{{ Numerals::localize((string) $report['rate']) }}%</td></tr>
                        @foreach ($report['counts'] as $status => $count)
                            <tr><td class="text-body-secondary">{{ __('app.schedules.statuses.'.$status) }}</td><td class="text-end">{{ Numerals::localize((string) $count) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.reports.monthly.by_type') }}</h3></div>
                <table class="table align-middle mb-0">
                    <tbody>
                        @forelse ($report['totals'] as $type => $amount)
                            <tr><td>{{ __('app.transactions.types.'.$type) }}</td><td class="text-end text-nowrap">{{ $amount->format() }}</td></tr>
                        @empty
                            <tr><td class="text-body-secondary">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="row g-3 mt-0">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.reports.monthly.loans_given') }}</h3></div>
                <table class="table align-middle mb-0">
                    <tbody>
                        <tr><td>{{ __('app.reports.monthly.loans_count', ['count' => Numerals::localize((string) $report['loans']['count'])]) }}</td><td class="text-end text-nowrap">{{ $report['loans']['principal']->format() }}</td></tr>
                        <tr><td>{{ __('app.accounts.agreed_extra') }}</td><td class="text-end text-nowrap">{{ $report['loans']['extra']->format() }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.reports.monthly.office_by_head') }}</h3></div>
                <table class="table align-middle mb-0">
                    <tbody>
                        @forelse ($report['heads'] as $line)
                            <tr>
                                <td>{{ $line['head']?->name ?? __('app.office_heads.none') }} <span class="small text-body-secondary">{{ __('app.transactions.types.'.$line['kind']->value) }}</span></td>
                                <td class="text-end text-nowrap">{{ $line['total']->format() }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-body-secondary">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <p class="small text-body-secondary mt-3">{{ __('app.reports.monthly.note') }}</p>

    @push('js')
        @vite('resources/js/admin/monthly-report.js')
    @endpush
@stop
