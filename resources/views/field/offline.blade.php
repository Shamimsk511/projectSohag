@extends('layouts.field')

@section('title', __('app.field.offline_title'))

{{-- Cached by the service worker and shown for any user, so no logout form (it would carry a stale CSRF token). --}}
@section('hide_logout', true)

@section('content')
    <div class="text-center py-5">
        <i class="bi bi-wifi-off display-1 text-secondary" aria-hidden="true"></i>
        <h1 class="h3 mt-3">{{ __('app.field.offline_title') }}</h1>
        <p class="text-secondary">{{ __('app.field.offline_text') }}</p>
        <a href="{{ route('field.home') }}" class="btn btn-primary btn-lg">
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            {{ __('app.field.retry') }}
        </a>
    </div>
@endsection
