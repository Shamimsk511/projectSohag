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
        @php
            $badge = match ($schedule->status) {
                \App\Enums\ScheduleStatus::Paid => 'text-bg-success',
                \App\Enums\ScheduleStatus::PartiallyPaid => 'text-bg-warning',
                \App\Enums\ScheduleStatus::Missed => 'text-bg-danger',
                default => 'text-bg-secondary',
            };
            $isOpen = in_array($schedule->status, [\App\Enums\ScheduleStatus::Pending, \App\Enums\ScheduleStatus::PartiallyPaid], true);
        @endphp
        <div class="card mb-2">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold">{{ $schedule->party->name }}</div>
                        <div class="small text-body-secondary">
                            {{ $schedule->party->party_code }} · {{ $schedule->party->area->name }} · {{ $schedule->account->account_number }}
                        </div>
                    </div>
                    <span class="badge {{ $badge }}">{{ __('app.schedules.statuses.'.$schedule->status->value) }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2 gap-2">
                    <div class="small">
                        {{ __('app.field.expected') }}: <span class="fw-semibold">{{ $schedule->expected_amount->format() }}</span><br>
                        {{ __('app.field.collected') }}: <span class="fw-semibold">{{ $collected[$schedule->id]->format() }}</span>
                    </div>
                    @if ($isOpen)
                        <a href="{{ route('field.collections.create', $schedule) }}" class="btn btn-primary btn-lg">
                            <i class="bi bi-cash-coin" aria-hidden="true"></i> {{ __('app.field.collect') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <p class="text-body-secondary">{{ __('app.field.empty') }}</p>
    @endforelse
@endsection
