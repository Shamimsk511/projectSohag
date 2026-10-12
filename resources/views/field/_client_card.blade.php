{{-- One client on the officer's list: photo, codes, name, then each account row. Needs $rows (one party), $collected, $remaining, $today. --}}
@php
    $party = $rows->first()->party;
    $open = $rows->filter(fn ($schedule) => $schedule->status->isCollectable());
    $openDue = $open->reduce(fn ($total, $schedule) => $total->plus($remaining[$schedule->id]), \App\Support\Money::zero());
    $search = mb_strtolower(implode(' ', array_filter([$party->name, $party->party_code, $party->client_number, $party->phone])));
@endphp
<div class="card mb-2" data-party-card="{{ $party->id }}" data-search="{{ $search }}">
    <div class="card-body">
        <div class="d-flex align-items-center gap-3">
            @if ($party->photo_path)
                <img src="{{ route('field.parties.photo', $party) }}" alt="" width="56" height="56" loading="lazy"
                     class="rounded-circle object-fit-cover flex-shrink-0 bg-body-secondary">
            @else
                <span class="rounded-circle bg-body-secondary d-inline-flex align-items-center justify-content-center flex-shrink-0 field-avatar" aria-hidden="true">
                    <i class="bi bi-person fs-3 text-body-secondary"></i>
                </span>
            @endif
            <div class="field-client-name">
                <div class="fw-semibold fs-5 text-truncate">{{ $party->name }}</div>
                <div class="small text-body-secondary">
                    {{ $party->party_code }}
                    @if ($party->client_number)
                        · {{ __('app.field.client_number') }} {{ $party->client_number }}
                    @endif
                    · {{ $party->area->name }}
                </div>
            </div>
        </div>

        @foreach ($rows as $schedule)
            @include('field._schedule_card')
        @endforeach

        @if ($open->count() > 1)
            <a href="{{ route('field.parties.collect', $party) }}" class="btn btn-primary btn-lg w-100 mt-3">
                <i class="bi bi-cash-stack" aria-hidden="true"></i> {{ __('app.field.collect_all') }}: {{ $openDue->format() }}
            </a>
        @endif
    </div>
</div>
