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
        $details = [
            __('app.parties.father_or_husband_name') => $party->father_or_husband_name,
            __('app.parties.mother_name') => $party->mother_name,
            __('app.parties.phone') => $party->phone ? \App\Support\Numerals::localize($party->phone) : null,
            __('app.parties.national_id') => $party->national_id ? \App\Support\Numerals::localize($party->national_id) : null,
            __('app.parties.date_of_birth') => $party->date_of_birth ? \App\Support\Numerals::localize($party->date_of_birth->format('d/m/Y')) : null,
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
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.parties.field_officer') }}</h3></div>
                <div class="card-body text-body-secondary">{{ __('app.parties.not_assigned') }}</div>
            </div>
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.parties.accounts') }}</h3></div>
                <div class="card-body text-body-secondary">{{ __('app.parties.no_accounts') }}</div>
            </div>
        </div>
    </div>
@stop
