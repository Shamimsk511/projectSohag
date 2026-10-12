@extends('layouts.field')

@section('title', $transaction->receipt_no)

@section('content')
    @php
        $date = fn (\Illuminate\Support\Carbon $value): string => \App\Support\Numerals::localize($value->format('d/m/Y'));
        $receivedBy = $transaction->fieldOfficer ?? $transaction->createdBy;
        $shareText = implode("\n", [
            config('app.name').' - '.__('app.receipts.title'),
            __('app.receipts.receipt_no').': '.$transaction->receipt_no,
            __('app.receipts.date').': '.$date($transaction->transaction_date),
            __('app.receipts.member').': '.$transaction->party?->name,
            __('app.receipts.amount').': '.$transaction->amount->format(),
        ]);
    @endphp

    @if (session('status'))
        <div class="alert alert-success d-print-none" role="status">{{ session('status') }}</div>
    @endif

    @if ($transaction->reversal)
        <div class="alert alert-danger">{{ __('app.receipts.reversed', ['date' => $date($transaction->reversal->transaction_date)]) }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            <div class="text-center mb-3">
                <div class="h5 mb-0">{{ config('app.name') }}</div>
                <div class="text-body-secondary">{{ __('app.receipts.title') }}</div>
            </div>
            <dl class="row mb-0">
                <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.receipt_no') }}</dt>
                <dd class="col-7 fw-semibold">{{ $transaction->receipt_no }}</dd>
                <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.date') }}</dt>
                <dd class="col-7">{{ $date($transaction->transaction_date) }}</dd>
                <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.member') }}</dt>
                <dd class="col-7">{{ $transaction->party?->name }} ({{ $transaction->party?->party_code }})</dd>
                <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.account') }}</dt>
                <dd class="col-7">{{ $transaction->account?->account_number }}</dd>
                <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.for') }}</dt>
                <dd class="col-7">{{ __('app.transactions.types.'.$transaction->type->value) }}</dd>
                <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.amount') }}</dt>
                <dd class="col-7 fs-4 fw-bold">{{ $transaction->amount->format() }}</dd>
                @if ($transaction->payment_method)
                    <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.payment_method') }}</dt>
                    <dd class="col-7">{{ __('app.transactions.payment_methods.'.$transaction->payment_method->value) }}</dd>
                @endif
                @if ($transaction->reference)
                    <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.reference') }}</dt>
                    <dd class="col-7">{{ $transaction->reference }}</dd>
                @endif
                <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.received_by') }}</dt>
                <dd class="col-7 mb-0">{{ $receivedBy?->name }}</dd>
            </dl>
        </div>
    </div>

    <div class="d-grid gap-2 mt-3 d-print-none">
        <button type="button" class="btn btn-primary btn-lg js-share d-none" data-share-title="{{ $transaction->receipt_no }}" data-share-text="{{ $shareText }}">
            <i class="bi bi-share" aria-hidden="true"></i> {{ __('app.receipts.share') }}
        </button>
        <button type="button" class="btn btn-outline-primary btn-lg js-print">
            <i class="bi bi-printer" aria-hidden="true"></i> {{ __('app.receipts.print') }}
        </button>
        <a href="{{ $backUrl }}" class="btn btn-link btn-lg">{{ __('app.receipts.back') }}</a>
    </div>
@endsection
