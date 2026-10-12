@extends('layouts.admin')

@section('subtitle', __('app.accounts.statement').' '.$account->account_number)
@section('content_header_title', __('app.accounts.statement'))
@section('content_header_subtitle', $account->account_number)

@section('content_body')
    @php($type = $account->account_type->value)

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @error('reason')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

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
            <button type="button" class="btn btn-outline-secondary ms-auto" data-print>
                <i class="bi bi-printer" aria-hidden="true"></i> {{ __('app.export.print') }}
            </button>
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

    @include('admin.accounts._statement_table', ['actions' => true])
@stop
