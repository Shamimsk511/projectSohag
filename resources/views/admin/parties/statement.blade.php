@extends('layouts.admin')

@section('subtitle', __('app.parties.statement').' '.$party->party_code)
@section('content_header_title', __('app.parties.statement'))
@section('content_header_subtitle', $party->name)

@section('content_body')
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2 flex-wrap">
            <h2 class="h5 mb-0"><a href="{{ route('admin.parties.show', $party) }}">{{ $party->name }}</a></h2>
            <span class="text-body-secondary">{{ $party->party_code }}</span>
            <button type="button" class="btn btn-outline-secondary ms-auto" data-print>
                <i class="bi bi-printer" aria-hidden="true"></i> {{ __('app.export.print') }}
            </button>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.parties.statement', $party) }}" class="row g-2 align-items-end">
                <div class="col-sm-4 col-lg-3">
                    <x-form.input name="from" type="date" :label="__('app.accounts.statement_from')" :value="request('from')" />
                </div>
                <div class="col-sm-4 col-lg-3">
                    <x-form.input name="to" type="date" :label="__('app.accounts.statement_to')" :value="request('to')" />
                </div>
                <div class="col-sm-4 col-lg-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">{{ __('app.accounts.statement_filter') }}</button>
                    <a href="{{ route('admin.parties.statement', $party) }}" class="btn btn-outline-secondary">{{ __('app.accounts.statement_all') }}</a>
                </div>
            </form>
        </div>
    </div>

    @if ($statements->isEmpty())
        <div class="alert alert-secondary">{{ __('app.parties.statement_no_accounts') }}</div>
    @else
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title mb-0">{{ __('app.parties.statement_summary') }}</h3></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('app.reports.outstanding.account') }}</th>
                            <th>{{ __('app.reports.outstanding.contract') }}</th>
                            <th class="text-end">{{ __('app.accounts.received') }} / {{ __('app.accounts.balance') }}</th>
                            <th class="text-end">{{ __('app.accounts.outstanding') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($statements as $item)
                            @php($account = $item['account'])
                            <tr>
                                <td class="text-nowrap">
                                    <a href="{{ route('admin.accounts.show', $account) }}">{{ $account->account_number }}</a>
                                    <span class="badge text-bg-info">{{ __('app.accounts.types.'.$account->account_type->value) }}</span>
                                    @unless ($account->isActive())
                                        <span class="badge text-bg-secondary">{{ __('app.accounts.closed') }}</span>
                                    @endunless
                                </td>
                                <td class="text-nowrap">{{ $account->isLoan() || $account->isFdr() ? $account->contract_amount?->format() : '' }}</td>
                                <td class="text-end text-nowrap">{{ $item['statement']->closing->format() }}</td>
                                <td class="text-end text-nowrap">{{ $account->isLoan() || $account->isFdr() ? $item['remaining']->format() : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @foreach ($statements as $item)
            <h3 class="h6 mt-4"><a href="{{ route('admin.accounts.statement', $item['account']) }}">{{ $item['account']->account_number }}</a></h3>
            @include('admin.accounts._statement_table', ['account' => $item['account'], 'statement' => $item['statement'], 'actions' => false])
        @endforeach
    @endif
@stop
