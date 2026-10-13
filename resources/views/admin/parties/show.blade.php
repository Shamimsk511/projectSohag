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

    @if ($party->accounts->isEmpty() && $canOpenAccount)
        <div class="card mb-3 edge-card tone-primary">
            <div class="card-body">
                <h2 class="h5">{{ __('app.parties.first_account') }}</h2>
                <p class="text-body-secondary">{{ __('app.parties.first_account_hint') }}</p>
                <div class="d-flex flex-wrap gap-2">
                    @foreach (['loan' => 'bi-cash-coin', 'saving' => 'bi-piggy-bank', 'fdr' => 'bi-bank'] as $type => $icon)
                        <a href="{{ route('admin.parties.accounts.create', [$party, 'type' => $type]) }}" class="btn btn-primary btn-lg flex-fill py-3">
                            <i class="bi {{ $icon }}" aria-hidden="true"></i> {{ __('app.accounts.types.'.$type) }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if ($party->accounts->isNotEmpty())
        @php
            $n = fn (int $value): string => \App\Support\Numerals::localize((string) $value);
        @endphp
        <div class="row g-3 mb-3">
            <div class="col-sm-6 col-lg-4">
                <div class="stat-tile tone-primary">
                    <div class="stat-tile__label"><i class="bi bi-arrow-down-left-circle" aria-hidden="true"></i>{{ __('app.parties.progress.owes_us') }}</div>
                    <div class="stat-tile__amount">{{ $totals['owes']->format() }}</div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="stat-tile tone-success">
                    <div class="stat-tile__label"><i class="bi bi-safe" aria-hidden="true"></i>{{ __('app.parties.progress.we_hold') }}</div>
                    <div class="stat-tile__amount">{{ $totals['holds']->format() }}</div>
                </div>
            </div>
            <div class="col-lg-4 d-flex align-items-center justify-content-lg-end">
                <a href="{{ route('admin.parties.statement', $party) }}" class="btn btn-outline-primary">
                    <i class="bi bi-journal-text" aria-hidden="true"></i> {{ __('app.parties.statement_link') }}
                </a>
            </div>
        </div>

        <div class="row g-3 mb-3">
            @foreach ($party->accounts as $account)
                @include('admin.parties._progress_card', [
                    'line' => $progress[$account->id],
                    'statementUrl' => route('admin.accounts.statement', $account),
                    'accountUrl' => route('admin.accounts.show', $account),
                ])
            @endforeach
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
            @if ($canManagePortal)
                @include('admin.parties._portal')
            @endif
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
