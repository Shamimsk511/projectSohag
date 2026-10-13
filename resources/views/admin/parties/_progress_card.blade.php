@php
    $date = fn (?\Illuminate\Support\Carbon $value): ?string => $value ? \App\Support\Numerals::localize($value->format('d/m/Y')) : null;
    $n = fn (int $value): string => \App\Support\Numerals::localize((string) $value);
@endphp
@php
    $rows = [];
    if ($account->isLoan()) {
        $rows = [
            __('app.parties.progress.given') => $line['given']?->format(),
            __('app.accounts.agreed_extra') => $line['extra']?->format(),
            __('app.accounts.contract_amount') => $line['contract']?->format(),
            __('app.accounts.received') => $line['received']?->format(),
        ];
    } elseif ($account->isFdr()) {
        $rows = [
            __('app.parties.progress.held') => $line['held']?->format(),
            __('app.accounts.agreed_payback') => $line['contract']?->format(),
        ];
    } else {
        $rows = [
            __('app.accounts.saved_so_far') => $line['saved']?->format(),
            __('app.accounts.agreed_amount') => $line['contract']?->format(),
        ];
    }
    $rows += [
        __('app.parties.progress.installment') => $line['installment']?->format(),
        __('app.parties.progress.next_due') => $date($line['next_due']),
        __('app.parties.progress.last_paid') => $date($line['last_paid']),
        __('app.parties.progress.missed') => $line['missed'] > 0 ? $n($line['missed']) : null,
        __('app.parties.progress.est_end') => $date($line['ends_on']),
    ];
@endphp
<div class="col-md-6 col-xl-4">
    <div @class(['card h-100 edge-card', 'tone-primary' => $account->isLoan() && $account->isActive(), 'tone-success' => ! $account->isLoan() && $account->isActive(), 'tone-muted opacity-75' => ! $account->isActive()])>
        <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2">
                @if ($accountUrl)
                <a href="{{ $accountUrl }}" class="fw-semibold">{{ $account->account_number }}</a>
            @else
                <span class="fw-semibold">{{ $account->account_number }}</span>
            @endif
                <span class="badge text-bg-info">{{ __('app.accounts.types.'.$account->account_type->value) }}</span>
                @unless ($account->isActive())
                    <span class="badge text-bg-secondary">{{ __('app.accounts.closed') }}</span>
                @endunless
            </div>
            <div class="text-body-secondary small">{{ $account->isLoan() ? __('app.accounts.outstanding') : ($account->isFdr() ? __('app.accounts.remaining_payback') : __('app.accounts.balance')) }}</div>
            <div class="display-6 fw-bold text-nowrap">{{ $line['remaining']->format() }}</div>
            @if ($line['total'] !== null)
                @php($percent = $line['total'] > 0 ? (int) round($line['paid'] * 100 / $line['total']) : 100)
                <div class="small mt-2">{{ __('app.parties.progress.installments', ['paid' => $n($line['paid']), 'total' => $n($line['total'])]) }}</div>
                <div class="progress mt-1" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100" style="height: .5rem">
                    <div class="progress-bar" style="width: {{ $percent }}%"></div>
                </div>
            @endif
            <dl class="row small mb-0 mt-3">
                @foreach (array_filter($rows, fn ($value) => $value !== null) as $label => $value)
                    <dt class="col-6 fw-normal text-body-secondary">{{ $label }}</dt>
                    <dd @class(['col-6 text-end mb-1 text-nowrap', 'text-danger fw-semibold' => $label === __('app.parties.progress.missed')])>{{ $value }}</dd>
                @endforeach
            </dl>
        </div>
        <div class="card-footer d-flex small">
            <a href="{{ $statementUrl }}"><i class="bi bi-journal-text" aria-hidden="true"></i> {{ __('app.parties.view_statement') }}</a>
            @if ($accountUrl)
                <a href="{{ $accountUrl }}" class="ms-auto">{{ __('app.parties.progress.account_page') }} <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            @endif
        </div>
    </div>
</div>
