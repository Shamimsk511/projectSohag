@extends('layouts.admin')

@section('subtitle', __('app.reports.cash_book.title'))
@section('content_header_title', __('app.reports.cash_book.title'))

@section('content_body')
    @php
        use App\Support\Numerals;

        $date = fn (\Illuminate\Support\Carbon $value): string => Numerals::localize($value->format('d/m/Y'));
        $columns = ['office_expense', 'office_income', 'loan_collection', 'loan_disbursement', 'saving_deposit', 'saving_withdrawal', 'investment_deposit', 'investment_return'];
    @endphp
    @include('admin.reports._period_form', ['route' => 'admin.reports.daily-cash-book', 'prefix' => 'app.reports.cash_book'])

    <div class="row g-3 mb-3">
        <div class="col-sm-6">
            <div class="card h-100"><div class="card-body">
                <div class="text-body-secondary small">{{ __('app.reports.cash_book.opening') }}</div>
                <div class="fs-4 fw-semibold">{{ $report['opening']->format() }}</div>
            </div></div>
        </div>
        <div class="col-sm-6">
            <div class="card h-100"><div class="card-body">
                <div class="text-body-secondary small">{{ __('app.reports.cash_book.closing') }}</div>
                <div class="fs-4 fw-semibold">{{ $report['closing']->format() }}</div>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0 js-report-table" data-export-name="daily-cash-book" data-excel-label="{{ __('app.export.excel') }}" data-print-label="{{ __('app.export.print') }}">
                <thead>
                    <tr>
                        <th>{{ __('app.reports.cash_book.date') }}</th>
                        @foreach ($columns as $column)
                            <th class="text-end">{{ __('app.reports.cash_book.'.$column) }}</th>
                        @endforeach
                        <th class="text-end">{{ __('app.reports.cash_book.net') }}</th>
                        <th class="text-end">{{ __('app.reports.cash_book.running') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['days'] as $day)
                        <tr>
                            <td class="text-nowrap">{{ $date($day['date']) }}</td>
                            @foreach ($columns as $column)
                                <td class="text-end text-nowrap">{{ $day[$column]->format() }}</td>
                            @endforeach
                            <td class="text-end text-nowrap">{{ $day['net']->format() }}</td>
                            <td class="text-end text-nowrap fw-semibold">{{ $day['running']->format() }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-semibold">
                        <td>{{ __('app.reports.cash_book.total') }}</td>
                        @foreach ($columns as $column)
                            <td class="text-end text-nowrap">{{ $report['totals'][$column]->format() }}</td>
                        @endforeach
                        <td class="text-end text-nowrap">{{ $report['closing']->minus($report['opening'])->format() }}</td>
                        <td class="text-end text-nowrap">{{ $report['closing']->format() }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card-footer small text-body-secondary">{{ __('app.reports.cash_book.note') }}</div>
    </div>
    @push('js')
        @vite('resources/js/admin/report-export.js')
    @endpush
@stop
