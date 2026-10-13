@extends('layouts.portal')

@section('title', __('app.portal.new_pin'))

@section('content')
    <div class="card mx-auto" style="max-width: 22rem">
        <div class="card-body">
            <h1 class="h4 text-center">{{ __('app.portal.new_pin') }}</h1>
            <p class="text-body-secondary text-center">{{ __('app.portal.new_pin_hint') }}</p>
            <form method="POST" action="{{ route('portal.pin.update') }}">
                @csrf
                <label class="form-label" for="pin">{{ __('app.portal.new_pin') }}</label>
                <input type="password" id="pin" name="pin" inputmode="numeric" autocomplete="new-password" maxlength="12" required
                       class="form-control form-control-lg text-center @error('pin') is-invalid @enderror">
                @error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <label class="form-label mt-3" for="pin_confirmation">{{ __('app.portal.new_pin_again') }}</label>
                <input type="password" id="pin_confirmation" name="pin_confirmation" inputmode="numeric" autocomplete="new-password" maxlength="12" required
                       class="form-control form-control-lg text-center">
                <button type="submit" class="btn btn-success btn-lg w-100 mt-3">{{ __('app.save') }}</button>
            </form>
        </div>
    </div>
@endsection
