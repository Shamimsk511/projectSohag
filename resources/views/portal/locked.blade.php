@extends('layouts.portal')

@section('content')
    <div class="alert alert-danger text-center my-5" role="alert">
        <i class="bi bi-lock-fill fs-3 d-block mb-2" aria-hidden="true"></i>
        {{ __('app.portal.locked') }}
    </div>
@endsection
