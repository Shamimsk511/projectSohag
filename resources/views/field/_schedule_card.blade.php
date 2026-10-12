{{-- One schedule row inside a client card. Needs $schedule, $collected, $remaining, $today. --}}
@php
    $badge = match ($schedule->status) {
        \App\Enums\ScheduleStatus::Paid => 'text-bg-success',
        \App\Enums\ScheduleStatus::PartiallyPaid => 'text-bg-warning',
        \App\Enums\ScheduleStatus::Missed => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
@endphp
<div class="border-top pt-2 mt-2" data-schedule-card="{{ $schedule->id }}">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div>
            <span class="fw-semibold">{{ __('app.accounts.types.'.$schedule->account->account_type->value) }}</span>
            · {{ $schedule->account->account_number }}
            @if ($schedule->due_date->toDateString() !== $today)
                <div class="text-danger">{{ __('app.field.earlier') }}: {{ \App\Support\Numerals::localize($schedule->due_date->format('d/m/Y')) }}</div>
            @endif
        </div>
        <span class="badge {{ $badge }}">{{ __('app.schedules.statuses.'.$schedule->status->value) }}</span>
    </div>
    <div class="row text-center g-1 mt-2">
        <div class="col-4">
            <div class="field-amount-label">{{ __('app.field.expected') }}</div>
            <div class="field-amount">{{ $schedule->expected_amount->format() }}</div>
        </div>
        <div class="col-4">
            <div class="field-amount-label">{{ __('app.field.collected') }}</div>
            <div class="field-amount text-success">{{ $collected[$schedule->id]->format() }}</div>
        </div>
        <div class="col-4">
            <div class="field-amount-label">{{ __('app.field.remaining') }}</div>
            <div class="field-amount text-danger">{{ $remaining[$schedule->id]->format() }}</div>
        </div>
    </div>
    <div class="fw-semibold text-warning-emphasis text-center d-none" data-unsynced></div>
    @if ($schedule->status->isCollectable())
        <a href="{{ route('field.collections.create', $schedule) }}" class="btn btn-outline-primary btn-lg w-100 mt-2">
            <i class="bi bi-cash-coin" aria-hidden="true"></i> {{ __('app.field.collect') }}
        </a>
    @endif
</div>
