{{-- One schedule row inside a client card. Needs $schedule, $collected, $remaining, $today. --}}
@php
    $type = $schedule->account->account_type;
    [$icon, $tone] = match ($type) {
        \App\Enums\AccountType::Loan => ['bi-cash-coin', 'text-danger'],
        \App\Enums\AccountType::Saving => ['bi-piggy-bank', 'text-success'],
        \App\Enums\AccountType::Fdr => ['bi-safe', 'text-primary'],
    };
    $due = $remaining[$schedule->id];
@endphp
{{-- One line per account: type (loan red, saving green) | what is still due | collect button, or a green tick once paid. --}}
<div class="field-account mb-2" data-schedule-card="{{ $schedule->id }}">
    <div class="field-due-row">
        <div class="field-type {{ $tone }}">
            <i class="bi {{ $icon }}" aria-hidden="true"></i>
            <div class="fw-bold text-nowrap">{{ __('app.accounts.types.'.$type->value) }}</div>
            @if ($schedule->due_date->toDateString() !== $today)
                <div class="small text-danger fw-semibold">{{ __('app.field.earlier') }}: {{ \App\Support\Numerals::localize($schedule->due_date->format('d/m/Y')) }}</div>
            @elseif ($due->isPositive() && $schedule->status !== \App\Enums\ScheduleStatus::Pending)
                <span class="badge text-bg-warning">{{ __('app.schedules.statuses.'.$schedule->status->value) }}</span>
            @endif
        </div>
        @if ($due->isPositive())
            <div class="field-min-0">
                <div class="field-amount-label">{{ __('app.field.remaining') }}</div>
                <div class="field-amount text-danger">{{ $due->format() }}</div>
            </div>
            @if ($schedule->status->isCollectable())
                <a href="{{ route('field.collections.create', $schedule) }}" class="btn btn-outline-primary btn-lg fw-semibold">{{ __('app.field.collect') }}</a>
            @endif
        @else
            <div class="field-min-0">
                <div class="field-amount-label">{{ __('app.field.collected') }}</div>
                <div class="field-amount text-success">{{ $collected[$schedule->id]->format() }}</div>
            </div>
            <div class="text-success fw-bold text-center">
                <i class="bi bi-check-circle-fill fs-2 d-block lh-1" aria-hidden="true"></i>
                {{ __('app.schedules.statuses.'.$schedule->status->value) }}
            </div>
        @endif
    </div>
    <div class="fw-semibold text-warning-emphasis text-center d-none" data-unsynced></div>
</div>
