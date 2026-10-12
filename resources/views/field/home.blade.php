@extends('layouts.field')

@section('title', __('app.today'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif
    @if (session('status_error'))
        <div class="alert alert-danger" role="alert">{{ session('status_error') }}</div>
    @endif

    @if ($todayClosed)
        <div class="alert alert-warning" role="status">{{ __('app.closings.today_closed') }}</div>
    @endif

    @include('field._queue_status', ['syncButton' => true, 'detailsLink' => true])

    <h1 class="h4 mb-3">{{ __('app.today') }}</h1>

    <div class="card mb-3">
        <div class="card-body">
            <div class="text-body-secondary small mb-2">{{ __('app.field.visits', ['count' => \App\Support\Numerals::localize((string) $schedules->count())]) }}</div>
            <div class="row text-center g-2">
                <div class="col-4">
                    <div class="field-amount-label">{{ __('app.field.expected') }}</div>
                    <div class="field-amount">{{ $expectedTotal->format() }}</div>
                </div>
                <div class="col-4">
                    <div class="field-amount-label">{{ __('app.field.collected') }}</div>
                    <div class="field-amount text-success">{{ $collectedTotal->format() }}</div>
                </div>
                <div class="col-4">
                    <div class="field-amount-label">{{ __('app.field.remaining') }}</div>
                    <div class="field-amount text-danger">{{ $remainingTotal->format() }}</div>
                </div>
            </div>
            <div class="d-flex justify-content-between border-top mt-3 pt-2">
                <span class="field-amount-label align-self-center">{{ __('app.cash.in_hand') }}</span>
                <span class="field-amount">{{ $cashInHand->format() }}</span>
            </div>
        </div>
    </div>

    @if ($schedules->isEmpty())
        <p class="text-body-secondary">{{ __('app.field.empty') }}</p>
    @endif

    @if ($clients->isNotEmpty())
        <div class="mb-3">
            <label for="party-search" class="visually-hidden">{{ __('app.field.search') }}</label>
            <input type="search" id="party-search" class="form-control form-control-lg js-party-search" autocomplete="off"
                   placeholder="{{ __('app.field.search') }}">
        </div>
        <p class="text-body-secondary d-none" data-party-no-match>{{ __('app.field.no_match') }}</p>
    @endif

    @foreach ($clients as $rows)
        @include('field._client_card')
    @endforeach

    {{-- Saved to IndexedDB by field.js; the offline page shows it when there is no connection. --}}
    <script type="application/json" id="field-today">@json($snapshot)</script>
@endsection
