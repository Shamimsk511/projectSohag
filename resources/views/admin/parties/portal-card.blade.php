@extends('layouts.field')

@section('title', __('app.portal.title'))
@section('hide_logout', true)

@section('content')
    <div class="card mx-auto text-center" style="max-width: 22rem">
        <div class="card-body">
            <div class="h5 mb-1">{{ config('app.name') }}</div>
            <div class="text-body-secondary mb-3">{{ __('app.portal.title') }}</div>
            <div class="mx-auto" style="width: 240px">{!! $qr !!}</div>
            <div class="fw-semibold mt-3">{{ __('app.portal.card_scan') }}</div>
            <div class="fs-5 mt-2">{{ $party->name }}</div>
            <div class="text-body-secondary">{{ $party->party_code }}</div>
            <div class="small mt-2">{{ __('app.portal.card_pin_hint') }}</div>
        </div>
    </div>
    <div class="text-center mt-3 d-print-none">
        <button type="button" class="btn btn-primary btn-lg js-print"><i class="bi bi-printer" aria-hidden="true"></i> {{ __('app.receipts.print') }}</button>
    </div>
@endsection
