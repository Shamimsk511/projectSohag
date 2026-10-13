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

    <h1 class="field-title mb-3">{{ __('app.field.title') }}</h1>

    <div class="card field-card mb-3">
        <div class="card-body">
            <div class="field-stats">
                <div>
                    <div class="field-amount-label">{{ __('app.field.expected') }}</div>
                    <div class="field-amount">{{ $expectedTotal->format() }}</div>
                </div>
                <div>
                    <div class="field-amount-label">{{ __('app.field.collected') }}</div>
                    <div class="field-amount text-success">{{ $collectedTotal->format() }}</div>
                </div>
                <div>
                    <div class="field-amount-label">{{ __('app.field.remaining') }}</div>
                    <div class="field-amount text-danger">{{ $remainingTotal->format() }}</div>
                </div>
            </div>
            <div class="field-stats field-stats-2 border-top mt-3 pt-3">
                <div>
                    <div class="field-amount-label">{{ __('app.cash.in_hand') }}</div>
                    <div class="field-amount">{{ $cashInHand->format() }}</div>
                </div>
                <div class="d-flex align-items-center gap-2 text-body-secondary">
                    <i class="bi bi-calendar-check fs-3 text-primary" aria-hidden="true"></i>
                    <span>{{ __('app.field.open_today', ['count' => \App\Support\Numerals::localize((string) $openToday)]) }}</span>
                </div>
            </div>
            @if ($schedules->isNotEmpty())
                <div class="border-top mt-3 pt-2 small text-body-secondary text-center">{{ $dayLine }}</div>
            @endif
        </div>
    </div>

    @if ($schedules->isEmpty())
        <p class="text-body-secondary">{{ __('app.field.empty') }}</p>
    @endif

    @if ($unpaidClients->isNotEmpty() || $paidClients->isNotEmpty())
        <div class="field-search mb-3">
            <label for="party-search" class="visually-hidden">{{ __('app.field.search') }}</label>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" id="party-search" class="form-control form-control-lg js-party-search" autocomplete="off"
                   placeholder="{{ __('app.field.search') }}">
        </div>
        <p class="text-body-secondary d-none" data-party-no-match>{{ __('app.field.no_match') }}</p>
    @endif

    @foreach ($unpaidClients as $rows)
        @include('field._client_card')
    @endforeach

    @if ($paidClients->isNotEmpty())
        <details class="mt-3" data-paid-clients>
            <summary class="btn btn-outline-success w-100 py-3 mb-2">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                {{ __('app.field.paid_section', ['count' => \App\Support\Numerals::localize((string) $paidClients->count())]) }}
            </summary>
            @foreach ($paidClients as $rows)
                @include('field._client_card')
            @endforeach
        </details>
    @endif

    {{-- Saved to IndexedDB by field.js; the offline page shows it when there is no connection. --}}
    <script type="application/json" id="field-today">@json($snapshot)</script>
@endsection
