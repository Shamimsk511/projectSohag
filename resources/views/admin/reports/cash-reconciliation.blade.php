@extends('layouts.admin')

@section('subtitle', __('app.reports.cash_reconciliation.title'))
@section('content_header_title', __('app.reports.cash_reconciliation.title'))

@php
    $office = $report['office'];
@endphp

@section('content_body')
    <form method="GET" action="{{ route('admin.reports.cash-reconciliation') }}" class="card mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label for="from" class="form-label">{{ __('app.reports.cash_reconciliation.from') }}</label>
                <input type="date" id="from" name="from" value="{{ $from->toDateString() }}" @class(['form-control', 'is-invalid' => $errors->has('from')])>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label for="to" class="form-label">{{ __('app.reports.cash_reconciliation.to') }}</label>
                <input type="date" id="to" name="to" value="{{ $to->toDateString() }}" @class(['form-control', 'is-invalid' => $errors->has('to')])>
                @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-lg-3">
                <button type="submit" class="btn btn-primary w-100">{{ __('app.reports.cash_reconciliation.show') }}</button>
            </div>
        </div>
    </form>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.reports.cash_reconciliation.officers') }}</h3></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0 js-report-table" data-export-name="cash-officers" data-excel-label="{{ __('app.export.excel') }}" data-print-label="{{ __('app.export.print') }}">
                        <thead>
                            <tr>
                                <th>{{ __('app.reports.cash_reconciliation.officer') }}</th>
                                <th class="text-end">{{ __('app.reports.cash_reconciliation.opening') }}</th>
                                <th class="text-end">{{ __('app.reports.cash_reconciliation.collected') }}</th>
                                <th class="text-end">{{ __('app.reports.cash_reconciliation.handed_over') }}</th>
                                <th class="text-end">{{ __('app.reports.cash_reconciliation.closing') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($report['officers'] as $line)
                                <tr>
                                    <td>{{ $line['officer']->name }}</td>
                                    <td class="text-end text-nowrap">{{ $line['opening']->format() }}</td>
                                    <td class="text-end text-nowrap">{{ $line['collected']->format() }}</td>
                                    <td class="text-end text-nowrap">{{ $line['handed_over']->format() }}</td>
                                    <td class="text-end text-nowrap fw-semibold">{{ $line['closing']->format() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-body-secondary py-4">{{ __('app.reports.cash_reconciliation.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                        @if ($report['officers'] !== [])
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td>{{ __('app.reports.cash_reconciliation.total') }}</td><td></td><td></td><td></td>
                                    <td class="text-end text-nowrap">{{ $report['officers_closing']->format() }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.reports.cash_reconciliation.office') }}</h3></div>
                <table class="table align-middle mb-0">
                    <tbody>
                        <tr><td>{{ __('app.reports.cash_reconciliation.opening') }}</td><td class="text-end text-nowrap">{{ $office['opening']->format() }}</td></tr>
                        <tr><td>+ {{ __('app.reports.cash_reconciliation.handovers') }}</td><td class="text-end text-nowrap">{{ $office['handovers']->format() }}</td></tr>
                        <tr><td>+ {{ __('app.reports.cash_reconciliation.other_in') }}</td><td class="text-end text-nowrap">{{ $office['other_in']->format() }}</td></tr>
                        <tr><td>− {{ __('app.reports.cash_reconciliation.out') }}</td><td class="text-end text-nowrap">{{ $office['out']->format() }}</td></tr>
                        <tr class="fw-semibold"><td>{{ __('app.reports.cash_reconciliation.closing') }}</td><td class="text-end text-nowrap">{{ $office['closing']->format() }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <p class="small text-body-secondary mt-3">{{ __('app.reports.cash_reconciliation.note') }}</p>
    @push('js')
        @vite('resources/js/admin/report-export.js')
    @endpush
@stop
