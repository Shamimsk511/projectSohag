@extends('layouts.admin')

@section('subtitle', __('app.reports.daily_collection.title'))
@section('content_header_title', __('app.reports.daily_collection.title'))

@php
    use App\Support\Numerals;
@endphp

@section('content_body')
    <form method="GET" action="{{ route('admin.reports.daily-collection') }}" class="card mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label for="date" class="form-label">{{ __('app.reports.daily_collection.day') }}</label>
                <input type="date" id="date" name="date" value="{{ $day->toDateString() }}" class="form-control">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label for="officer" class="form-label">{{ __('app.reports.daily_collection.officer') }}</label>
                <select id="officer" name="officer" class="form-select">
                    <option value="">{{ __('app.reports.daily_collection.all_officers') }}</option>
                    @foreach ($officers as $option)
                        <option value="{{ $option->id }}" @selected($officer === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <button type="submit" class="btn btn-primary w-100">{{ __('app.reports.daily_collection.show') }}</button>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-body-secondary small">{{ __('app.reports.daily_collection.total') }}</div>
                <div class="fs-3 fw-semibold">{{ $report['total']->format() }}</div>
                <div class="small text-body-secondary">{{ __('app.reports.daily_collection.entries', ['count' => Numerals::localize((string) $report['rows']->count())]) }}</div>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-body-secondary small mb-1">{{ __('app.reports.daily_collection.by_officer') }}</div>
                @forelse ($report['by_officer'] as $line)
                    <div class="d-flex justify-content-between">
                        <span>{{ $line['name'] ?? __('app.reports.daily_collection.office') }} ({{ Numerals::localize((string) $line['count']) }})</span>
                        <span class="text-nowrap">{{ $line['total']->format() }}</span>
                    </div>
                @empty
                    <span class="text-body-secondary">—</span>
                @endforelse
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100"><div class="card-body">
                <div class="text-body-secondary small mb-1">{{ __('app.reports.daily_collection.by_method') }}</div>
                @forelse ($report['by_method'] as $line)
                    <div class="d-flex justify-content-between">
                        <span>{{ $line['method'] ? __('app.transactions.payment_methods.'.$line['method']->value) : '—' }} ({{ Numerals::localize((string) $line['count']) }})</span>
                        <span class="text-nowrap">{{ $line['total']->format() }}</span>
                    </div>
                @empty
                    <span class="text-body-secondary">—</span>
                @endforelse
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0 js-report-table" data-export-name="daily-collection" data-excel-label="{{ __('app.export.excel') }}" data-print-label="{{ __('app.export.print') }}">
                <thead>
                    <tr>
                        <th>{{ __('app.reports.daily_collection.receipt') }}</th>
                        <th>{{ __('app.reports.daily_collection.member') }}</th>
                        <th>{{ __('app.reports.daily_collection.account') }}</th>
                        <th>{{ __('app.reports.daily_collection.collected_by') }}</th>
                        <th>{{ __('app.reports.daily_collection.method') }}</th>
                        <th class="text-end">{{ __('app.reports.daily_collection.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="text-nowrap">
                                @if ($row->receipt_no)
                                    <a href="{{ route('admin.transactions.receipt', $row) }}">{{ $row->receipt_no }}</a>
                                @endif
                            </td>
                            <td>{{ $row->party?->name }}</td>
                            <td class="text-nowrap">{{ $row->account?->account_number }}</td>
                            <td>{{ $row->fieldOfficer?->name ?? __('app.reports.daily_collection.office') }}</td>
                            <td>{{ $row->payment_method ? __('app.transactions.payment_methods.'.$row->payment_method->value) : '—' }}</td>
                            <td class="text-end text-nowrap">{{ $row->amount->format() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-body-secondary py-4">{{ __('app.reports.daily_collection.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @push('js')
        @vite('resources/js/admin/report-export.js')
    @endpush
@stop
