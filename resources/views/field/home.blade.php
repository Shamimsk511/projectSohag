@extends('layouts.field')

@section('title', __('app.today'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
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
                    <div class="small text-body-secondary">{{ __('app.field.expected') }}</div>
                    <div class="fw-semibold">{{ $expectedTotal->format() }}</div>
                </div>
                <div class="col-4">
                    <div class="small text-body-secondary">{{ __('app.field.collected') }}</div>
                    <div class="fw-semibold text-success">{{ $collectedTotal->format() }}</div>
                </div>
                <div class="col-4">
                    <div class="small text-body-secondary">{{ __('app.field.remaining') }}</div>
                    <div class="fw-semibold text-danger">{{ $remainingTotal->format() }}</div>
                </div>
            </div>
            <div class="d-flex justify-content-between border-top mt-3 pt-2">
                <span class="text-body-secondary">{{ __('app.cash.in_hand') }}</span>
                <span class="fw-semibold">{{ $cashInHand->format() }}</span>
            </div>
        </div>
    </div>

    @forelse ($schedules as $schedule)
        @include('field._schedule_card')
    @empty
        <p class="text-body-secondary">{{ __('app.field.empty') }}</p>
    @endforelse

    @if ($earlier->isNotEmpty())
        <h2 class="h5 mt-4 mb-2">{{ __('app.field.earlier') }}</h2>
        @foreach ($earlier as $schedule)
            @include('field._schedule_card', ['showDate' => true])
        @endforeach
    @endif

    {{-- Saved to IndexedDB by field.js; the offline page shows it when there is no connection. --}}
    <script type="application/json" id="field-today">@json($snapshot)</script>
@endsection
