@extends('layouts.portal')

@section('content')
    <div class="text-center py-5">
        <i class="bi bi-qr-code-scan display-4 text-success" aria-hidden="true"></i>
        <h1 class="h4 mt-3">{{ __('app.portal.title') }}</h1>
        <p class="text-body-secondary">{{ __('app.portal.start_hint') }}</p>
    </div>
@endsection
