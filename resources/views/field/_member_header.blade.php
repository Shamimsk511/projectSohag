{{-- Collect pages header: back button, then the member's name; tapping the name opens a summary of every open account.
     Needs $party, $accounts (open accounts) and $progress (ReportService::memberAccounts). Native <details>: no Bootstrap JS on the field entry. --}}
<a href="{{ route('field.home') }}" class="btn btn-light border btn-lg fw-semibold mb-3 field-back">
    <i class="bi bi-arrow-left" aria-hidden="true"></i> {{ __('app.field.back') }}
</a>

<details class="card field-card mb-3 field-member">
    <summary class="card-body d-flex align-items-center gap-3">
        <div class="field-min-0 flex-grow-1">
            <h1 class="fs-4 fw-bold mb-0 text-truncate">{{ $party->name }}</h1>
            <div class="text-body-secondary">
                {{ __('app.field.member_code') }} {{ $party->party_code }}
                @if ($party->client_number)
                    · {{ __('app.field.client_number') }} {{ $party->client_number }}
                @endif
            </div>
        </div>
        <span class="text-primary fw-semibold text-nowrap">
            {{ __('app.field.member_summary') }} <i class="bi bi-chevron-down field-member-chevron" aria-hidden="true"></i>
        </span>
    </summary>
    <div class="card-body border-top pt-2">
        @forelse ($accounts as $account)
            @php
                $line = $progress[$account->id];
                $figures = match (true) {
                    $account->isLoan() => [
                        __('app.parties.progress.given') => $line['given'],
                        __('app.accounts.contract_amount') => $line['contract'],
                        __('app.accounts.received') => $line['received'],
                    ],
                    $account->isFdr() => [
                        __('app.parties.progress.held') => $line['held'],
                        __('app.accounts.agreed_payback') => $line['contract'],
                    ],
                    default => [
                        __('app.accounts.saved_so_far') => $line['saved'],
                        __('app.accounts.agreed_amount') => $line['contract'],
                    ],
                };
                [$remainingLabel, $tone] = match (true) {
                    $account->isLoan() => [__('app.accounts.outstanding'), 'text-danger'],
                    $account->isFdr() => [__('app.accounts.remaining_payback'), 'text-primary'],
                    default => [__('app.accounts.balance'), 'text-success'],
                };
            @endphp
            <div @class(['py-2', 'border-top' => ! $loop->first])>
                <div class="fw-bold {{ $tone }}">{{ __('app.accounts.types.'.$account->account_type->value) }} · <span class="fw-normal text-body-secondary">{{ $account->account_number }}</span></div>
                <dl class="row mb-0">
                    @foreach (array_filter($figures) as $label => $amount)
                        <dt class="col-7 fw-normal text-body-secondary">{{ $label }}</dt>
                        <dd class="col-5 text-end mb-0">{{ $amount->format() }}</dd>
                    @endforeach
                    <dt class="col-7 fw-semibold">{{ $remainingLabel }}</dt>
                    <dd class="col-5 text-end mb-0 fw-bold {{ $tone }}">{{ $line['remaining']->format() }}</dd>
                </dl>
                @if ($line['total'])
                    <div class="small text-body-secondary">{{ __('app.parties.progress.installments', ['paid' => \App\Support\Numerals::localize((string) $line['paid']), 'total' => \App\Support\Numerals::localize((string) $line['total'])]) }}</div>
                @endif
            </div>
        @empty
            <p class="text-body-secondary mb-0">{{ __('app.portal.no_accounts') }}</p>
        @endforelse
    </div>
</details>

<a href="tel:{{ $party->phone }}" class="btn btn-outline-secondary btn-lg mb-3">
    <i class="bi bi-telephone" aria-hidden="true"></i> {{ \App\Support\Numerals::localize($party->phone) }}
</a>
