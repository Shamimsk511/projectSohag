@extends('layouts.portal')

@section('title', $account->account_number)

@section('content')
    <a href="{{ route('portal.home') }}" class="btn btn-link px-0 d-print-none"><i class="bi bi-arrow-left" aria-hidden="true"></i> {{ __('app.portal.back') }}</a>
    <h1 class="h4">{{ $account->account_number }} · {{ __('app.accounts.types.'.$account->account_type->value) }}</h1>
    <form method="GET" class="row g-2 align-items-end mb-3 d-print-none">
        <div class="col-6"><x-form.input name="from" type="date" :label="__('app.accounts.statement_from')" :value="request('from')" /></div>
        <div class="col-6"><x-form.input name="to" type="date" :label="__('app.accounts.statement_to')" :value="request('to')" /></div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-success">{{ __('app.accounts.statement_filter') }}</button>
            <button type="button" class="btn btn-outline-secondary js-print"><i class="bi bi-printer" aria-hidden="true"></i> {{ __('app.receipts.print') }}</button>
        </div>
    </form>
    @include('admin.accounts._statement_table', ['actions' => false, 'receiptRoute' => 'portal.receipts.show', 'showNotes' => false])
@endsection
