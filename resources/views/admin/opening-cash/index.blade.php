@extends('layouts.admin')

@section('subtitle', __('app.opening_cash.title'))
@section('content_header_title', __('app.opening_cash.title'))

@section('content_body')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card" style="max-width: 40rem">
        @if ($balance)
            <div class="card-body">
                <p class="text-body-secondary mb-1">{{ __('app.opening_cash.amount') }}</p>
                <p class="fs-3 fw-bold mb-3">{{ $balance->amount->format() }}</p>
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('app.opening_cash.as_of') }}</dt>
                    <dd class="col-sm-8">{{ \App\Support\Numerals::localize($balance->as_of->format('d/m/Y')) }}</dd>
                    <dt class="col-sm-4">{{ __('app.opening_cash.set_by') }}</dt>
                    <dd class="col-sm-8">{{ $balance->setBy?->name }}</dd>
                    @if ($balance->note)
                        <dt class="col-sm-4">{{ __('app.opening_cash.note') }}</dt>
                        <dd class="col-sm-8">{{ $balance->note }}</dd>
                    @endif
                </dl>
            </div>
            <div class="card-footer small text-body-secondary">{{ __('app.opening_cash.locked') }}</div>
        @else
            <form method="POST" action="{{ route('admin.opening-cash.store') }}">
                @csrf
                <div class="card-body">
                    <p class="text-body-secondary">{{ __('app.opening_cash.explain') }}</p>
                    <x-form.input name="amount" :label="__('app.opening_cash.amount').' (৳)'" inputmode="decimal" autocomplete="off" required />
                    <x-form.input name="as_of" type="date" :label="__('app.opening_cash.as_of')" :value="$today" :max="$today" required />
                    <x-form.input name="note" :label="__('app.opening_cash.note')" maxlength="500" />
                    <p class="small text-danger mb-0">{{ __('app.opening_cash.once_warning') }}</p>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> {{ __('app.opening_cash.save') }}
                    </button>
                </div>
            </form>
        @endif
    </div>
@stop
