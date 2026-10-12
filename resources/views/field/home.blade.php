@extends('layouts.field')

@section('title', __('app.today'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

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
@endsection
