@extends('layouts.admin')

@section('subtitle', $account->account_number)
@section('content_header_title', __('app.accounts.title'))
@section('content_header_subtitle', $account->account_number)

@section('content_body')
    @php
        $date = fn (?\Illuminate\Support\Carbon $value): ?string => $value ? \App\Support\Numerals::localize($value->format('d/m/Y')) : null;
        $party = $account->party;
        $kind = $account->account_type->value;
        $agreed = $account->contract_amount;
        // Right-hand sections, in order; only those this user may use. The first one opens unless a form error points elsewhere.
        $sections = $account->isActive() ? array_filter([
            'entry' => $canPost,
            'plan' => $canManage,
            'date' => $canSchedule,
            'amount' => $canManage,
            'close' => $canManage,
        ]) : [];
        $errorSection = match (true) {
            $errors->hasAny(['type', 'amount', 'transaction_date', 'payment_method', 'reference', 'description', 'account_id']) => 'entry',
            $errors->hasAny(['frequency', 'installment_amount', 'weekdays', 'starts_on', 'ends_on', 'duration', 'duration_unit']) => 'plan',
            $errors->hasAny(['due_date', 'expected_amount', 'notes']) => 'date',
            $errors->hasAny(['contract_amount', 'reason']) => 'amount',
            $errors->has('close_reason') => 'close',
            default => null,
        };
        $openSection = $errorSection ?? array_key_first($sections);
    @endphp

    @if (session('status'))
        <div class="alert alert-success d-flex align-items-center gap-2 fs-6">
            <i class="bi bi-check-circle-fill fs-4" aria-hidden="true"></i> {{ session('status') }}
        </div>
    @endif

    <div class="account-page">
        {{-- Summary: who, which account, and the one figure that matters most. --}}
        <section class="account-page__summary">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-7">
                            <div class="d-flex align-items-start gap-3">
                                <span class="account-avatar" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
                                <div class="min-w-0">
                                    <h2 class="account-name mb-1">
                                        <a href="{{ route('admin.parties.show', $party) }}" class="text-reset text-decoration-none">{{ $party->name }}</a>
                                        <span @class(['badge fs-6 align-middle', 'text-bg-success' => $account->isActive(), 'text-bg-secondary' => ! $account->isActive()])>
                                            <i @class(['bi', 'bi-check-circle' => $account->isActive(), 'bi-lock' => ! $account->isActive()]) aria-hidden="true"></i>
                                            {{ $account->isActive() ? __('app.accounts.active') : __('app.accounts.closed') }}
                                        </span>
                                    </h2>
                                    <div class="text-body-secondary">
                                        {{ __('app.accounts.member_code') }}: <span class="fw-semibold text-body">{{ $party->party_code }}</span>
                                        @if ($party->client_number)
                                            · {{ __('app.parties.client_number') }}: <span class="fw-semibold text-body">{{ $party->client_number }}</span>
                                        @endif
                                    </div>
                                    <div class="text-body-secondary">
                                        {{ __('app.accounts.number') }}: <span class="fw-semibold text-body">{{ $account->account_number }}</span>
                                        · <span class="badge text-bg-info">{{ __('app.accounts.types.'.$kind) }}</span>
                                    </div>
                                    <a href="tel:{{ $party->phone }}" class="d-inline-block mt-1 text-decoration-none">
                                        <i class="bi bi-telephone" aria-hidden="true"></i> {{ \App\Support\Numerals::localize($party->phone) }}
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="account-balance">
                                <div class="text-body-secondary">{{ $account->isLoan() ? __('app.accounts.outstanding') : __('app.accounts.current_balance') }}</div>
                                <div @class(['account-balance__amount', 'text-danger' => $account->isLoan() && $remaining->isPositive()])>{{ $remaining->format() }}</div>
                                <div class="small text-body-secondary">
                                    @if ($account->isLoan())
                                        {{ __('app.accounts.contract_amount') }}: {{ $agreed?->format() }}
                                        @if ($agreedExtra)
                                            ({{ __('app.accounts.agreed_extra') }}: {{ $agreedExtra->format() }})
                                        @endif
                                        · {{ __('app.accounts.received') }}: {{ $received->format() }}
                                    @else
                                        {{ __('app.accounts.saved_so_far') }}: {{ $received->format() }}
                                        @if ($agreed)
                                            · {{ __('app.accounts.remaining_to_agreed') }}: {{ $agreed->minus($received)->format() }}
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 mt-2 account-facts">
                        <div class="col-6 col-md-4">
                            <div class="account-fact">
                                <i class="bi bi-calendar-event" aria-hidden="true"></i>
                                <div>
                                    <div class="small text-body-secondary">{{ __('app.accounts.opened_at') }}</div>
                                    <div class="fw-semibold">{{ $date($account->opened_at) }}</div>
                                </div>
                            </div>
                        </div>
                        @if ($agreed)
                            <div class="col-6 col-md-4">
                                <div class="account-fact">
                                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                    <div>
                                        <div class="small text-body-secondary">{{ $account->isLoan() ? __('app.accounts.contract_amount') : __('app.accounts.agreed_amount') }}</div>
                                        <div class="fw-semibold">{{ $agreed->format() }}</div>
                                    </div>
                                </div>
                            </div>
                        @endif
                        @unless ($account->isActive())
                            <div class="col-12 col-md-4">
                                <div class="account-fact">
                                    <i class="bi bi-lock" aria-hidden="true"></i>
                                    <div>
                                        <div class="small text-body-secondary">{{ __('app.accounts.closed_at') }}</div>
                                        <div class="fw-semibold">{{ $date($account->closed_at) }} · {{ $account->closedBy?->name }}</div>
                                        @if ($account->close_reason)
                                            <div class="small">{{ $account->close_reason }}</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endunless
                    </div>

                    @if ($sections)
                        <h3 class="visually-hidden">{{ __('app.accounts.actions_title') }}</h3>
                        <div class="row g-2 mt-3">
                            @isset($sections['entry'])
                                <div class="col-6">
                                    <button type="button" class="btn btn-success account-action w-100" data-account-section="entry">
                                        <i class="bi bi-wallet2" aria-hidden="true"></i> {{ __('app.accounts.actions.entry_'.$kind) }}
                                    </button>
                                </div>
                            @endisset
                            @isset($sections['plan'])
                                <div class="col-6">
                                    <button type="button" class="btn btn-primary account-action w-100" data-account-section="plan">
                                        <i class="bi bi-calendar-week" aria-hidden="true"></i> {{ __('app.accounts.actions.plan_'.$kind) }}
                                    </button>
                                </div>
                            @endisset
                            @isset($sections['date'])
                                <div class="col-6">
                                    <button type="button" class="btn btn-outline-primary account-action w-100" data-account-section="date">
                                        <i class="bi bi-calendar-plus" aria-hidden="true"></i> {{ __('app.accounts.actions.add_date') }}
                                    </button>
                                </div>
                            @endisset
                            @isset($sections['close'])
                                <div class="col-6">
                                    <button type="button" class="btn btn-outline-danger account-action w-100" data-account-section="close">
                                        <i class="bi bi-lock" aria-hidden="true"></i> {{ __('app.accounts.actions.close') }}
                                    </button>
                                </div>
                            @endisset
                        </div>
                    @elseif (! $account->isActive())
                        <div class="alert alert-secondary mt-3 mb-0">
                            <i class="bi bi-lock" aria-hidden="true"></i> {{ __('app.accounts.closed_notice') }}
                        </div>
                    @endif
                </div>
            </div>
        </section>

        {{-- Action forms: one open at a time. --}}
        @if ($sections)
            <aside class="account-page__actions">
                <div class="accordion account-sections mb-3" id="account-sections">
                    @isset($sections['entry'])
                        @include('admin.accounts._section', [
                            'key' => 'entry', 'icon' => 'bi-wallet2', 'tone' => 'success',
                            'title' => __('app.accounts.actions.entry_'.$kind),
                            'body' => 'admin.accounts._entry_form',
                        ])
                    @endisset
                    @isset($sections['plan'])
                        @include('admin.accounts._section', [
                            'key' => 'plan', 'icon' => 'bi-calendar-week', 'tone' => 'primary',
                            'title' => __('app.accounts.actions.plan_'.$kind),
                            'body' => 'admin.accounts._plan_form',
                        ])
                    @endisset
                    @isset($sections['date'])
                        @include('admin.accounts._section', [
                            'key' => 'date', 'icon' => 'bi-calendar-plus', 'tone' => 'primary',
                            'title' => __('app.accounts.actions.add_date'),
                            'body' => 'admin.accounts._schedule_form',
                        ])
                    @endisset
                    @isset($sections['amount'])
                        @include('admin.accounts._section', [
                            'key' => 'amount', 'icon' => 'bi-pencil-square', 'tone' => 'secondary',
                            'title' => $account->isLoan() ? __('app.accounts.adjust') : __('app.accounts.adjust_agreed'),
                            'body' => 'admin.accounts._amount_form',
                        ])
                    @endisset
                    @isset($sections['close'])
                        @include('admin.accounts._section', [
                            'key' => 'close', 'icon' => 'bi-lock', 'tone' => 'danger',
                            'title' => __('app.accounts.actions.close'),
                            'body' => 'admin.accounts._close_form',
                        ])
                    @endisset
                </div>
            </aside>
        @endif

        {{-- History: latest entries, schedule, amount changes. --}}
        <section class="account-page__history">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center">
                    <h3 class="card-title mb-0"><i class="bi bi-receipt me-1" aria-hidden="true"></i> {{ __('app.accounts.recent') }}</h3>
                    <a href="{{ route('admin.accounts.statement', $account) }}" class="ms-auto fw-semibold text-decoration-none">
                        {{ __('app.accounts.see_all') }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
                @if ($recent)
                    <ul class="list-group list-group-flush account-entries">
                        @foreach ($recent as $line)
                            @php
                                $transaction = $line->transaction;
                                $isReversal = $transaction->type === \App\Enums\TransactionType::Reversal;
                                $amount = $line->in ?? $line->out;
                                $tone = $isReversal || $line->reversed ? 'secondary' : ($line->in ? 'success' : 'danger');
                            @endphp
                            <li class="list-group-item py-3">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="badge rounded-pill text-bg-{{ $tone }} bg-opacity-75">
                                        <i @class(['bi', 'bi-arrow-down-left' => $line->in && ! $isReversal, 'bi-arrow-up-right' => $line->out && ! $isReversal, 'bi-arrow-counterclockwise' => $isReversal]) aria-hidden="true"></i>
                                        {{ __('app.transactions.types.'.$transaction->type->value) }}
                                    </span>
                                    @if ($line->reversed)
                                        <span class="badge text-bg-warning">{{ __('app.accounts.statement_reversed') }}</span>
                                    @endif
                                    <span class="text-body-secondary">{{ $date($transaction->transaction_date) }}</span>
                                    <span @class(['ms-auto fs-5 fw-semibold text-nowrap', 'text-success' => $tone === 'success', 'text-danger' => $tone === 'danger', 'text-decoration-line-through text-body-secondary' => $line->reversed])>
                                        {{ $amount?->format() }}
                                    </span>
                                </div>
                                <div class="d-flex flex-wrap gap-2 small text-body-secondary mt-1">
                                    @if ($transaction->receipt_no)
                                        <a href="{{ route('admin.transactions.receipt', $transaction) }}" target="_blank" rel="noopener">{{ $transaction->receipt_no }}</a>
                                    @elseif ($transaction->reference)
                                        <span>{{ $transaction->reference }}</span>
                                    @endif
                                    @if ($transaction->description)
                                        <span>{{ $transaction->description }}</span>
                                    @endif
                                    <span class="ms-auto">{{ __('app.accounts.statement_running.'.$kind) }}: <span class="fw-semibold text-body">{{ $line->running->format() }}</span></span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="card-body text-center text-body-secondary py-5">
                        <i class="bi bi-inbox display-6 d-block mb-2" aria-hidden="true"></i>
                        <div class="fw-semibold text-body">{{ __('app.accounts.no_entries') }}</div>
                        <div class="small">{{ __('app.accounts.no_entries_hint') }}</div>
                    </div>
                @endif
            </div>

            @include('admin.accounts._schedules')

            @if ($account->isLoan() || $account->adjustments->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title mb-0">{{ __($account->isLoan() ? 'app.accounts.adjustments' : 'app.accounts.agreed_changes') }}</h3>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse ($account->adjustments as $adjustment)
                            <li class="list-group-item">
                                <div class="fw-semibold">
                                    {{ __('app.accounts.adjustment_line', ['old' => $adjustment->old_amount?->format() ?? __('app.accounts.not_set_amount'), 'new' => $adjustment->new_amount->format()]) }}
                                </div>
                                <div>{{ $adjustment->reason }}</div>
                                <small class="text-body-secondary">
                                    {{ \App\Support\Numerals::localize($adjustment->created_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i')) }}
                                    · {{ $adjustment->createdBy->name }}
                                </small>
                            </li>
                        @empty
                            <li class="list-group-item text-body-secondary">{{ __('app.accounts.no_adjustments') }}</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </section>
    </div>

    @if ($sections)
        @push('js')
            @vite('resources/js/admin/accounts.js')
        @endpush
    @endif
@stop
