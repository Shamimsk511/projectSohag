@extends('layouts.admin')

@section('subtitle', __('app.reports.loan_register.title'))
@section('content_header_title', __('app.reports.loan_register.title'))

@section('content_body')
    @php
        use App\Support\Numerals;

        $date = fn (\Illuminate\Support\Carbon $value): string => Numerals::localize($value->format('d/m/Y'));
    @endphp
    @include('admin.reports._period_form', ['route' => 'admin.reports.loan-register', 'prefix' => 'app.reports.loan_register'])

    <div class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0 js-report-table" data-export-name="loan-register" data-excel-label="{{ __('app.export.excel') }}" data-print-label="{{ __('app.export.print') }}">
                <thead>
                    <tr>
                        <th>{{ __('app.reports.loan_register.date') }}</th>
                        <th>{{ __('app.reports.loan_register.member') }}</th>
                        <th>{{ __('app.reports.loan_register.account') }}</th>
                        <th>{{ __('app.reports.loan_register.officer') }}</th>
                        <th class="text-end">{{ __('app.reports.loan_register.principal') }}</th>
                        <th class="text-end">{{ __('app.accounts.agreed_extra') }}</th>
                        <th class="text-end">{{ __('app.reports.loan_register.contract') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $line)
                        @php($party = $line['account']->party)
                        <tr>
                            <td class="text-nowrap">{{ $date($line['transaction']->transaction_date) }}</td>
                            <td>
                                <a href="{{ route('admin.parties.show', $party) }}">{{ $party->name }}</a>
                                <div class="small text-body-secondary">{{ $party->client_number ?? $party->party_code }}</div>
                            </td>
                            <td class="text-nowrap"><a href="{{ route('admin.accounts.show', $line['account']) }}">{{ $line['account']->account_number }}</a></td>
                            <td>{{ $line['officer']?->name }}</td>
                            <td class="text-end text-nowrap">{{ $line['transaction']->amount->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line['extra']?->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line['contract']?->format() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-body-secondary py-4">{{ __('app.reports.loan_register.empty') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($report['rows'] !== [])
                    <tfoot>
                        <tr class="fw-semibold">
                            <td>{{ __('app.reports.loan_register.total') }}</td>
                            <td>{{ __('app.reports.loan_register.count', ['count' => Numerals::localize((string) $report['count'])]) }}</td>
                            <td></td><td></td>
                            <td class="text-end text-nowrap">{{ $report['principal']->format() }}</td>
                            <td class="text-end text-nowrap">{{ $report['extra']->format() }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <div class="card-footer small text-body-secondary">{{ __('app.reports.loan_register.note') }}</div>
    </div>
    @push('js')
        @vite('resources/js/admin/report-export.js')
    @endpush
@stop
