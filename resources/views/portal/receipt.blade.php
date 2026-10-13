@extends('layouts.portal')

@section('title', $transaction->receipt_no)

@section('content')
    @if ($transaction->reversal)
        <div class="alert alert-danger">{{ __('app.receipts.reversed', ['date' => \App\Support\Numerals::localize($transaction->reversal->transaction_date->format('d/m/Y'))]) }}</div>
    @endif

    @include('receipts._card')

    <div class="d-grid gap-2 mt-3 d-print-none">
        <button type="button" class="btn btn-outline-primary btn-lg js-print">
            <i class="bi bi-printer" aria-hidden="true"></i> {{ __('app.receipts.print') }}
        </button>
        <a href="{{ route('portal.accounts.show', $transaction->account_id) }}" class="btn btn-link btn-lg">{{ __('app.portal.back') }}</a>
    </div>
@endsection
