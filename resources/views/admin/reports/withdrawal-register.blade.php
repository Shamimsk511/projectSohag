@extends('layouts.admin')

@section('subtitle', __('app.reports.withdrawal_register.title'))
@section('content_header_title', __('app.reports.withdrawal_register.title'))

@section('content_body')
    @php
        use App\Support\Numerals;

        $date = fn (\Illuminate\Support\Carbon $value): string => Numerals::localize($value->format('d/m/Y'));
    @endphp
    @include('admin.reports._period_form', ['route' => 'admin.reports.withdrawal-register', 'prefix' => 'app.reports.withdrawal_register'])

    <div class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0 js-report-table" data-export-name="withdrawal-register" data-excel-label="{{ __('app.export.excel') }}" data-print-label="{{ __('app.export.print') }}">
                <thead>
                    <tr>
                        <th>{{ __('app.reports.withdrawal_register.date') }}</th>
                        <th>{{ __('app.reports.withdrawal_register.member') }}</th>
                        <th>{{ __('app.reports.withdrawal_register.account') }}</th>
                        <th>{{ __('app.reports.withdrawal_register.method') }}</th>
                        <th class="text-end">{{ __('app.reports.withdrawal_register.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="text-nowrap">{{ $date($row->transaction_date) }}</td>
                            <td>
                                @if ($row->party)
                                    <a href="{{ route('admin.parties.show', $row->party) }}">{{ $row->party->name }}</a>
                                    <div class="small text-body-secondary">{{ $row->party->client_number ?? $row->party->party_code }}</div>
                                @endif
                            </td>
                            <td class="text-nowrap">
                                @if ($row->account)
                                    <a href="{{ route('admin.accounts.show', $row->account) }}">{{ $row->account->account_number }}</a>
                                @endif
                            </td>
                            <td>{{ $row->payment_method ? __('app.transactions.payment_methods.'.$row->payment_method->value) : '' }}</td>
                            <td class="text-end text-nowrap">{{ $row->amount->format() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-body-secondary py-4">{{ __('app.reports.withdrawal_register.empty') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($report['rows']->isNotEmpty())
                    <tfoot>
                        <tr class="fw-semibold">
                            <td>{{ __('app.reports.withdrawal_register.total') }}</td><td></td><td></td><td></td>
                            <td class="text-end text-nowrap">{{ $report['total']->format() }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <div class="card-footer small text-body-secondary">{{ __('app.reports.withdrawal_register.note') }}</div>
    </div>
    @push('js')
        @vite('resources/js/admin/report-export.js')
    @endpush
@stop
