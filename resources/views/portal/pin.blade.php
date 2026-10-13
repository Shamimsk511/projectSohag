@extends('layouts.portal')

@section('title', __('app.portal.enter_pin'))

@section('content')
    <div class="card mx-auto" style="max-width: 22rem">
        <div class="card-body text-center">
            <i class="bi bi-shield-lock display-5 text-success" aria-hidden="true"></i>
            <h1 class="h4 mt-2">{{ __('app.portal.enter_pin') }}</h1>
            <div class="text-body-secondary mb-3">{{ $access->party->name }}</div>
            <form method="POST" action="{{ route('portal.pin.check') }}">
                @csrf
                <input type="password" name="pin" inputmode="numeric" autocomplete="off" maxlength="12" required autofocus
                       class="form-control form-control-lg text-center @error('pin') is-invalid @enderror" aria-label="{{ __('app.portal.enter_pin') }}">
                @error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button type="submit" class="btn btn-success btn-lg w-100 mt-3">{{ __('app.portal.open') }}</button>
            </form>
            <p class="small text-body-secondary mt-3 mb-0">{{ __('app.portal.forgot_pin') }}</p>
        </div>
    </div>
@endsection
