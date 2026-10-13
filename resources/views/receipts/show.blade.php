@extends('layouts.field')

@section('title', $transaction->receipt_no)

@section('content')
    @php
        $date = fn (\Illuminate\Support\Carbon $value): string => \App\Support\Numerals::localize($value->format('d/m/Y'));
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

    @include('receipts._card')

    <div class="d-grid gap-2 mt-3 d-print-none">
        <x-whatsapp.receipt :transaction="$transaction" class="w-100" />
        <button type="button" class="btn btn-primary btn-lg js-share d-none" data-share-title="{{ $transaction->receipt_no }}" data-share-text="{{ $shareText }}">
            <i class="bi bi-share" aria-hidden="true"></i> {{ __('app.receipts.share') }}
        </button>
        <button type="button" class="btn btn-outline-primary btn-lg js-print">
            <i class="bi bi-printer" aria-hidden="true"></i> {{ __('app.receipts.print') }}
        </button>
        <a href="{{ $backUrl }}" class="btn btn-link btn-lg">{{ __('app.receipts.back') }}</a>
    </div>
@endsection
