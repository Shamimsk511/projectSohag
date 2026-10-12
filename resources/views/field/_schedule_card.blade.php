{{-- One schedule row on the officer's list. Needs $schedule, $collected, $remaining; $showDate for arrears. --}}
@php
    $badge = match ($schedule->status) {
        \App\Enums\ScheduleStatus::Paid => 'text-bg-success',
        \App\Enums\ScheduleStatus::PartiallyPaid => 'text-bg-warning',
        \App\Enums\ScheduleStatus::Missed => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
@endphp
<div class="card mb-2">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
                <div class="fw-semibold">{{ $schedule->party->name }}</div>
                <div class="small text-body-secondary">
                    {{ $schedule->party->party_code }} · {{ $schedule->party->area->name }} · {{ $schedule->account->account_number }}
                </div>
                @if ($showDate ?? false)
                    <div class="small text-body-secondary">{{ __('app.schedules.due_date') }}: {{ \App\Support\Numerals::localize($schedule->due_date->format('d/m/Y')) }}</div>
                @endif
            </div>
            <span class="badge {{ $badge }}">{{ __('app.schedules.statuses.'.$schedule->status->value) }}</span>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-2 gap-2">
            <div class="small">
                {{ __('app.field.expected') }}: <span class="fw-semibold">{{ $schedule->expected_amount->format() }}</span><br>
                {{ __('app.field.collected') }}: <span class="fw-semibold">{{ $collected[$schedule->id]->format() }}</span><br>
                {{ __('app.field.remaining') }}: <span class="fw-semibold">{{ $remaining[$schedule->id]->format() }}</span>
            </div>
            @if ($schedule->status->isCollectable())
                <a href="{{ route('field.collections.create', $schedule) }}" class="btn btn-primary btn-lg">
                    <i class="bi bi-cash-coin" aria-hidden="true"></i> {{ __('app.field.collect') }}
                </a>
            @endif
        </div>
    </div>
</div>
