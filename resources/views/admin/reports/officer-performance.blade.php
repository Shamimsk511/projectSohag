@extends('layouts.admin')

@section('subtitle', __('app.reports.officer_performance.title'))
@section('content_header_title', __('app.reports.officer_performance.title'))

@php
    use App\Support\Money;
    use App\Support\Numerals;

    $number = fn (int $value): string => Numerals::localize((string) $value);
@endphp

@section('content_body')
    <form method="GET" action="{{ route('admin.reports.officer-performance') }}" class="card mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label for="from" class="form-label">{{ __('app.reports.officer_performance.from') }}</label>
                <input type="date" id="from" name="from" value="{{ $from->toDateString() }}" @class(['form-control', 'is-invalid' => $errors->has('from')])>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label for="to" class="form-label">{{ __('app.reports.officer_performance.to') }}</label>
                <input type="date" id="to" name="to" value="{{ $to->toDateString() }}" @class(['form-control', 'is-invalid' => $errors->has('to')])>
                @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-sm-6 col-lg-3">
                <button type="submit" class="btn btn-primary w-100">{{ __('app.reports.officer_performance.show') }}</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0 js-report-table" data-export-name="officer-performance" data-excel-label="{{ __('app.export.excel') }}" data-print-label="{{ __('app.export.print') }}">
                <thead>
                    <tr>
                        <th>{{ __('app.reports.officer_performance.officer') }}</th>
                        <th class="text-end">{{ __('app.reports.officer_performance.rows') }}</th>
                        <th class="text-end">{{ __('app.reports.officer_performance.paid') }}</th>
                        <th class="text-end">{{ __('app.reports.officer_performance.partial') }}</th>
                        <th class="text-end">{{ __('app.reports.officer_performance.missed') }}</th>
                        <th class="text-end">{{ __('app.reports.officer_performance.expected') }}</th>
                        <th class="text-end">{{ __('app.reports.officer_performance.collected') }}</th>
                        <th class="text-end">{{ __('app.reports.officer_performance.rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lines as $line)
                        <tr>
                            <td>{{ $line['officer']->name }}</td>
                            <td class="text-end">{{ $number($line['rows']) }}</td>
                            <td class="text-end">{{ $number($line['paid']) }}</td>
                            <td class="text-end">{{ $number($line['partial']) }}</td>
                            <td class="text-end">{{ $number($line['missed']) }}</td>
                            <td class="text-end text-nowrap">{{ $line['expected']->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line['collected']->format() }}</td>
                            <td class="text-end">{{ $number($line['rate']) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-body-secondary py-4">{{ __('app.reports.officer_performance.empty') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($lines !== [])
                    <tfoot>
                        <tr class="fw-semibold">
                            <td>{{ __('app.reports.officer_performance.total') }}</td>
                            <td class="text-end">{{ $number(array_sum(array_column($lines, 'rows'))) }}</td>
                            <td class="text-end">{{ $number(array_sum(array_column($lines, 'paid'))) }}</td>
                            <td class="text-end">{{ $number(array_sum(array_column($lines, 'partial'))) }}</td>
                            <td class="text-end">{{ $number(array_sum(array_column($lines, 'missed'))) }}</td>
                            <td class="text-end text-nowrap">{{ Money::fromPoisha(array_sum(array_map(fn ($line) => $line['expected']->poisha, $lines)))->format() }}</td>
                            <td class="text-end text-nowrap">{{ Money::fromPoisha(array_sum(array_map(fn ($line) => $line['collected']->poisha, $lines)))->format() }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <div class="card-footer small text-body-secondary">{{ __('app.reports.officer_performance.note') }}</div>
    </div>
    @push('js')
        @vite('resources/js/admin/report-export.js')
    @endpush
@stop
