@extends('layouts.portal')

@section('title', __('app.portal.title'))

@section('content')
    <h1 class="h4 mb-1">{{ $party->name }}</h1>
    <div class="text-body-secondary mb-3">{{ $party->party_code }}</div>
    <div class="row g-3">
        @forelse ($party->accounts as $account)
            @include('admin.parties._progress_card', [
                'line' => $progress[$account->id],
                'statementUrl' => route('portal.accounts.show', $account),
                'accountUrl' => null,
            ])
        @empty
            <p class="text-body-secondary">{{ __('app.portal.no_accounts') }}</p>
        @endforelse
    </div>
@endsection
