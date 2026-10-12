@extends('layouts.admin')

@section('subtitle', __('app.accounts.statement').' '.$account->account_number)
@section('content_header_title', __('app.accounts.statement'))
@section('content_header_subtitle', $account->account_number)

@section('content_body')
    @php
        $date = fn (?\Illuminate\Support\Carbon $value): ?string => $value ? \App\Support\Numerals::localize($value->format('d/m/Y')) : null;
        $type = $account->account_type->value;
    @endphp

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2 flex-wrap">
            <h2 class="h5 mb-0">
                <a href="{{ route('admin.accounts.show', $account) }}">{{ $account->account_number }}</a>
            </h2>
            <span class="badge text-bg-info">{{ __('app.accounts.types.'.$type) }}</span>
            <span class="text-body-secondary">
                <a href="{{ route('admin.parties.show', $account->party) }}">{{ $account->party->name }}</a>
                ({{ $account->party->party_code }})
            </span>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.accounts.statement', $account) }}" class="row g-2 align-items-end">
                <div class="col-sm-4 col-lg-3">
                    <x-form.input name="from" type="date" :label="__('app.accounts.statement_from')" :value="request('from')" />
                </div>
                <div class="col-sm-4 col-lg-3">
                    <x-form.input name="to" type="date" :label="__('app.accounts.statement_to')" :value="request('to')" />
                </div>
                <div class="col-sm-4 col-lg-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">{{ __('app.accounts.statement_filter') }}</button>
                    <a href="{{ route('admin.accounts.statement', $account) }}" class="btn btn-outline-secondary">{{ __('app.accounts.statement_all') }}</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.accounts.statement_date') }}</th>
                        <th>{{ __('app.accounts.statement_particulars') }}</th>
                        <th>{{ __('app.accounts.statement_receipt') }}</th>
                        <th class="text-end">{{ __('app.accounts.statement_in.'.$type) }}</th>
                        <th class="text-end">{{ __('app.accounts.statement_out.'.$type) }}</th>
                        <th class="text-end">{{ __('app.accounts.statement_running.'.$type) }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="fw-semibold">
                        <td>{{ $date($statement->from) }}</td>
                        <td colspan="4">{{ __('app.accounts.statement_opening') }}</td>
                        <td class="text-end">{{ $statement->opening->format() }}</td>
                    </tr>
                    @forelse ($statement->lines as $line)
                        @php($transaction = $line->transaction)
                        <tr>
                            <td class="text-nowrap">{{ $date($transaction->transaction_date) }}</td>
                            <td>
                                {{ __('app.transactions.types.'.$transaction->type->value) }}
                                @if ($line->reversed)
                                    <span class="badge text-bg-warning">{{ __('app.accounts.statement_reversed') }}</span>
                                @endif
                                @if ($transaction->reversedTransaction)
                                    <div class="small text-body-secondary">
                                        {{ __('app.accounts.statement_reversal_of', ['ref' => $transaction->reversedTransaction->receipt_no ?? $date($transaction->reversedTransaction->transaction_date)]) }}
                                    </div>
                                @endif
                                @if ($transaction->description)
                                    <div class="small text-body-secondary">{{ $transaction->description }}</div>
                                @endif
                            </td>
                            <td class="text-nowrap">{{ $transaction->receipt_no ?? $transaction->reference }}</td>
                            <td class="text-end text-nowrap">{{ $line->in?->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line->out?->format() }}</td>
                            <td class="text-end text-nowrap">{{ $line->running->format() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-body-secondary">{{ __('app.accounts.statement_empty') }}</td>
                        </tr>
                    @endforelse
                    <tr class="fw-semibold">
                        <td>{{ $date($statement->to) }}</td>
                        <td colspan="4">{{ __('app.accounts.statement_closing') }}</td>
                        <td class="text-end">{{ $statement->closing->format() }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@stop
