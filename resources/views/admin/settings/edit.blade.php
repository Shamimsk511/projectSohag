@extends('layouts.admin')

@section('subtitle', __('app.settings.title'))
@section('content_header_title', __('app.settings.title'))

@section('content_body')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="row">
        <div class="col-lg-6">
            <form method="POST" action="{{ route('admin.settings.update') }}" class="card">
                @csrf
                @method('PUT')
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.settings.cash_handover_mode') }}</h3></div>
                <div class="card-body">
                    @foreach (\App\Enums\CashHandoverMode::cases() as $mode)
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="cash_handover_mode" id="mode_{{ $mode->value }}"
                                   value="{{ $mode->value }}" @checked(old('cash_handover_mode', $cashHandoverMode->value) === $mode->value)>
                            <label class="form-check-label" for="mode_{{ $mode->value }}">
                                <span class="fw-semibold">{{ __('app.settings.modes.'.$mode->value) }}</span>
                                <span class="d-block small text-body-secondary">{{ __('app.settings.mode_hints.'.$mode->value) }}</span>
                            </label>
                        </div>
                    @endforeach
                    @error('cash_handover_mode')<div class="text-danger small">{{ $message }}</div>@enderror
                    <p class="small text-body-secondary">{{ __('app.settings.mode_switch_hint') }}</p>

                    <h3 class="h6 mt-4">{{ __('app.settings.auto_close_days') }}</h3>
                    <input type="hidden" name="auto_close_days" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="auto_close_days" id="auto_close_days"
                               value="1" @checked(old('auto_close_days', $autoCloseDays ? '1' : '0') === '1')>
                        <label class="form-check-label" for="auto_close_days">
                            {{ __('app.settings.auto_close_days_on') }}
                            <span class="d-block small text-body-secondary">{{ __('app.settings.auto_close_days_hint') }}</span>
                        </label>
                    </div>
                    @error('auto_close_days')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">{{ __('app.settings.save') }}</button>
                </div>
            </form>
        </div>
    </div>
@stop
