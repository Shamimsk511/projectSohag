@extends('layouts.admin')

@section('subtitle', __('app.reports.missed_partial.title'))
@section('content_header_title', __('app.reports.missed_partial.title'))

@php
    use App\Support\Numerals;
@endphp

@section('content_body')
    <form method="GET" action="{{ route('admin.reports.missed-partial') }}" class="card mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-sm-6 col-lg-2">
                <label for="from" class="form-label">{{ __('app.reports.missed_partial.from') }}</label>
                <input type="date" id="from" name="from" value="{{ $from->toDateString() }}" @class(['form-control', 'is-invalid' => $errors->has('from')])>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="to" class="form-label">{{ __('app.reports.missed_partial.to') }}</label>
                <input type="date" id="to" name="to" value="{{ $to->toDateString() }}" @class(['form-control', 'is-invalid' => $errors->has('to')])>
                @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-lg-3">
                <label for="status" class="form-label">{{ __('app.reports.missed_partial.status') }}</label>
                <select id="status" name="status" class="form-select">
                    <option value="">{{ __('app.reports.missed_partial.all_statuses') }}</option>
                    @foreach ([\App\Enums\ScheduleStatus::Missed, \App\Enums\ScheduleStatus::PartiallyPaid] as $case)
                        <option value="{{ $case->value }}" @selected($status === $case)>{{ __('app.schedules.statuses.'.$case->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label for="officer" class="form-label">{{ __('app.reports.missed_partial.officer') }}</label>
                <select id="officer" name="officer" class="form-select">
                    <option value="">{{ __('app.reports.missed_partial.all_officers') }}</option>
                    @foreach ($officers as $option)
                        <option value="{{ $option->id }}" @selected($officer === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <button type="submit" class="btn btn-primary w-100">{{ __('app.reports.missed_partial.show') }}</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0 js-report-table" data-export-name="missed-partial" data-excel-label="{{ __('app.export.excel') }}" data-print-label="{{ __('app.export.print') }}">
                <thead>
                    <tr>
                        <th>{{ __('app.reports.missed_partial.due_date') }}</th>
                        <th>{{ __('app.reports.missed_partial.member') }}</th>
                        <th>{{ __('app.reports.missed_partial.account') }}</th>
                        <th>{{ __('app.reports.missed_partial.officer') }}</th>
                        <th>{{ __('app.reports.missed_partial.status') }}</th>
                        <th class="text-end">{{ __('app.reports.missed_partial.expected') }}</th>
                        <th class="text-end">{{ __('app.reports.missed_partial.collected') }}</th>
                        <th class="text-end">{{ __('app.reports.missed_partial.remaining') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $line)
                        @php($schedule = $line['schedule'])
                        <tr>
                            <td class="text-nowrap">{{ Numerals::localize($schedule->due_date->format('d/m/Y')) }}</td>
                            <td>
                                {{ $schedule->party->name }}
                                <div class="small text-body-secondary">{{ $schedule->party->phone }}</div>
                            </td>
                            <td class="text-nowrap"><a href="{{ route('admin.accounts.show', $schedule->account) }}">{{ $schedule->account->account_number }}</a></td>
                            <td>{{ $schedule->fieldOfficer?->name ?? __('app.reports.missed_partial.unassigned') }}</td>
                            <td>{{ __('app.schedules.statuses.'.$schedule->status->value) }}</td>
                            <td class="text-end text-nowrap">{{ $schedule->expected_amount->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line['collected']->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line['remaining']->format() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-body-secondary py-4">{{ __('app.reports.missed_partial.empty') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($report['rows'] !== [])
                    <tfoot>
                        <tr class="fw-semibold">
                            <td>{{ __('app.reports.missed_partial.total') }}</td><td></td><td></td><td></td><td></td>
                            <td class="text-end text-nowrap">{{ $report['expected']->format() }}</td>
                            <td class="text-end text-nowrap">{{ $report['collected']->format() }}</td>
                            <td class="text-end text-nowrap">{{ $report['remaining']->format() }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <div class="card-footer small text-body-secondary">{{ __('app.reports.missed_partial.note') }}</div>
    </div>
    @push('js')
        @vite('resources/js/admin/report-export.js')
    @endpush
@stop
