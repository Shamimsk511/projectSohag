@extends('layouts.admin')

@section('subtitle', $party->name)
@section('content_header_title', __('app.parties.title'))
@section('content_header_subtitle', $party->party_code)

@section('content_body')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php
        $notSet = __('app.parties.not_set');
        $date = fn (?\Illuminate\Support\Carbon $value): ?string => $value ? \App\Support\Numerals::localize($value->format('d/m/Y')) : null;
        $details = [
            __('app.parties.client_number') => $party->client_number ? \App\Support\Numerals::localize($party->client_number) : null,
            __('app.parties.father_or_husband_name') => $party->father_or_husband_name,
            __('app.parties.mother_name') => $party->mother_name,
            __('app.parties.phone') => $party->phone ? \App\Support\Numerals::localize($party->phone) : null,
            __('app.parties.national_id') => $party->national_id ? \App\Support\Numerals::localize($party->national_id) : null,
            __('app.parties.date_of_birth') => $date($party->date_of_birth),
            __('app.parties.gender') => $party->gender ? __('app.genders.'.$party->gender->value) : null,
            __('app.parties.area') => $party->area->name,
            __('app.parties.address') => $party->address,
        ];
        $nominee = [
            __('app.parties.nominee_name') => $party->nominee_name,
            __('app.parties.nominee_relation') => $party->nominee_relation,
            __('app.parties.nominee_phone') => $party->nominee_phone ? \App\Support\Numerals::localize($party->nominee_phone) : null,
            __('app.parties.nominee_national_id') => $party->nominee_national_id ? \App\Support\Numerals::localize($party->nominee_national_id) : null,
        ];
    @endphp

    @if ($party->accounts->isNotEmpty())
        <div class="row g-3 mb-3">
            @foreach ($party->accounts as $account)
                <div class="col-sm-6 col-xl-3">
                    <a href="{{ route('admin.accounts.statement', $account) }}"
                       @class(['card h-100 text-decoration-none border-2', 'border-primary' => $account->isLoan() && $account->isActive(), 'border-success' => ! $account->isLoan() && $account->isActive(), 'opacity-75' => ! $account->isActive()])>
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="fw-semibold">{{ $account->account_number }}</span>
                                <span class="badge text-bg-info">{{ __('app.accounts.types.'.$account->account_type->value) }}</span>
                                @unless ($account->isActive())
                                    <span class="badge text-bg-secondary">{{ __('app.accounts.closed') }}</span>
                                @endunless
                            </div>
                            <div class="text-body-secondary small">{{ $account->isLoan() ? __('app.accounts.outstanding') : __('app.accounts.balance') }}</div>
                            <div class="display-6 fw-bold text-nowrap">{{ $balances[$account->id]->format() }}</div>
                        </div>
                        <div class="card-footer small text-body-secondary">
                            <i class="bi bi-journal-text" aria-hidden="true"></i> {{ __('app.parties.view_statement') }}
                            <i class="bi bi-chevron-right float-end" aria-hidden="true"></i>
                        </div>
                    </a>
                </div>
            @endforeach
            <div class="col-sm-6 col-xl-3">
                <a href="{{ route('admin.parties.statement', $party) }}" class="card h-100 text-decoration-none bg-body-tertiary">
                    <div class="card-body">
                        <div class="text-body-secondary small">{{ __('app.parties.total_saving') }}</div>
                        <div class="fs-4 fw-bold text-nowrap mb-2">{{ $totals['saving']->format() }}</div>
                        <div class="text-body-secondary small">{{ __('app.parties.total_loan') }}</div>
                        <div class="fs-4 fw-bold text-nowrap">{{ $totals['loan']->format() }}</div>
                    </div>
                    <div class="card-footer small text-body-secondary">
                        <i class="bi bi-journal-text" aria-hidden="true"></i> {{ __('app.parties.statement_link') }}
                        <i class="bi bi-chevron-right float-end" aria-hidden="true"></i>
                    </div>
                </a>
            </div>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center gap-3">
                    @if ($party->photo_path)
                        <img src="{{ route('admin.parties.photo', $party) }}" alt="{{ $party->name }}" class="rounded border" width="72" height="72" style="object-fit: cover">
                    @else
                        <span class="d-inline-flex align-items-center justify-content-center rounded border bg-body-tertiary" style="width: 72px; height: 72px">
                            <i class="bi bi-person fs-2 text-body-secondary" aria-hidden="true"></i>
                        </span>
                    @endif
                    <div>
                        <h2 class="h5 mb-1">{{ $party->name }}</h2>
                        <span class="text-body-secondary">{{ $party->party_code }}</span>
                        <span @class(['badge ms-2', 'text-bg-success' => $party->isActive(), 'text-bg-secondary' => ! $party->isActive()])>
                            {{ __('app.statuses.'.$party->status->value) }}
                        </span>
                    </div>
                    @can('update', $party)
                        <a href="{{ route('admin.parties.edit', $party) }}" class="btn btn-outline-primary ms-auto">
                            <i class="bi bi-pencil" aria-hidden="true"></i> {{ __('app.edit') }}
                        </a>
                    @endcan
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        @foreach ($details as $label => $value)
                            <dt class="col-sm-4">{{ $label }}</dt>
                            <dd class="col-sm-8">{{ $value ?? $notSet }}</dd>
                        @endforeach
                    </dl>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.parties.nominee') }}</h3></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        @foreach ($nominee as $label => $value)
                            <dt class="col-sm-4">{{ $label }}</dt>
                            <dd class="col-sm-8">{{ $value ?? $notSet }}</dd>
                        @endforeach
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            @include('admin.parties._assignment')
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center">
                    <h3 class="card-title mb-0">{{ __('app.parties.accounts') }}</h3>
                    <a href="{{ route('admin.parties.statement', $party) }}" class="btn btn-sm btn-outline-secondary ms-2">
                        <i class="bi bi-journal-text" aria-hidden="true"></i> {{ __('app.parties.statement_link') }}
                    </a>
                    @if ($party->isActive())
                        @can('create', \App\Models\Account::class)
                            <a href="{{ route('admin.parties.accounts.create', $party) }}" class="btn btn-sm btn-primary ms-auto">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ __('app.accounts.open') }}
                            </a>
                        @endcan
                    @endif
                </div>
                <div class="list-group list-group-flush">
                    @forelse ($party->accounts as $account)
                        <a href="{{ route('admin.accounts.show', $account) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-3">
                            <span class="fw-semibold">{{ $account->account_number }}</span>
                            <span class="badge text-bg-info">{{ __('app.accounts.types.'.$account->account_type->value) }}</span>
                            @unless ($account->isActive())
                                <span class="badge text-bg-secondary">{{ __('app.accounts.closed') }}</span>
                            @endunless
                            <i class="bi bi-chevron-right ms-auto text-body-secondary" aria-hidden="true"></i>
                        </a>
                    @empty
                        <div class="list-group-item text-body-secondary">{{ __('app.parties.no_accounts') }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@stop
