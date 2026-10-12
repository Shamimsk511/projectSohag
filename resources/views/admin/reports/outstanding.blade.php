@extends('layouts.admin')

@section('subtitle', __('app.reports.outstanding.title'))
@section('content_header_title', __('app.reports.outstanding.title'))

@php
    use App\Support\Numerals;
@endphp

@section('content_body')
    <form method="GET" action="{{ route('admin.reports.outstanding') }}" class="card mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label for="area" class="form-label">{{ __('app.reports.outstanding.area') }}</label>
                <select id="area" name="area" class="form-select">
                    <option value="">{{ __('app.reports.outstanding.all_areas') }}</option>
                    @foreach ($areas as $option)
                        <option value="{{ $option->id }}" @selected($area === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="overdue" name="overdue" value="1" @checked($overdueOnly)>
                    <label class="form-check-label" for="overdue">{{ __('app.reports.outstanding.overdue_only') }}</label>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <button type="submit" class="btn btn-primary w-100">{{ __('app.reports.outstanding.show') }}</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0 js-report-table" data-export-name="outstanding" data-excel-label="{{ __('app.export.excel') }}" data-print-label="{{ __('app.export.print') }}">
                <thead>
                    <tr>
                        <th>{{ __('app.reports.outstanding.member') }}</th>
                        <th>{{ __('app.reports.outstanding.account') }}</th>
                        <th>{{ __('app.reports.outstanding.officer') }}</th>
                        <th class="text-end">{{ __('app.reports.outstanding.contract') }}</th>
                        <th class="text-end">{{ __('app.accounts.agreed_extra') }}</th>
                        <th class="text-end">{{ __('app.reports.outstanding.received') }}</th>
                        <th class="text-end">{{ __('app.reports.outstanding.outstanding') }}</th>
                        <th class="text-end">{{ __('app.reports.outstanding.overdue') }}</th>
                        <th>{{ __('app.reports.outstanding.overdue_since') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['lines'] as $line)
                        @php($party = $line['account']->party)
                        <tr>
                            <td>
                                <a href="{{ route('admin.parties.show', $party) }}">{{ $party->name }}</a>
                                <div class="small text-body-secondary">{{ $party->phone }}@if ($party->area) · {{ $party->area->name }}@endif</div>
                            </td>
                            <td class="text-nowrap"><a href="{{ route('admin.accounts.show', $line['account']) }}">{{ $line['account']->account_number }}</a></td>
                            <td>{{ $party->activeAssignment?->fieldOfficer?->name ?? __('app.reports.outstanding.unassigned') }}</td>
                            <td class="text-end text-nowrap">{{ $line['contract']->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line['extra']?->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line['received']->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line['outstanding']->format() }}</td>
                            <td class="text-end text-nowrap">
                                @if ($line['overdue']->isPositive())
                                    {{ $line['overdue']->format() }}
                                    <div class="small text-body-secondary">{{ __('app.reports.outstanding.rows', ['count' => Numerals::localize((string) $line['overdue_rows'])]) }}</div>
                                @endif
                            </td>
                            <td class="text-nowrap">{{ $line['oldest_due'] ? Numerals::localize($line['oldest_due']->format('d/m/Y')) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-body-secondary py-4">{{ __('app.reports.outstanding.empty') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($report['lines'] !== [])
                    <tfoot>
                        <tr class="fw-semibold">
                            <td>{{ __('app.reports.outstanding.total') }}</td><td></td><td></td><td></td><td></td><td></td>
                            <td class="text-end text-nowrap">{{ $report['outstanding']->format() }}</td>
                            <td class="text-end text-nowrap">{{ $report['overdue']->format() }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <div class="card-footer small text-body-secondary">{{ __('app.reports.outstanding.note') }}</div>
    </div>
    @push('js')
        @vite('resources/js/admin/report-export.js')
    @endpush
@stop
