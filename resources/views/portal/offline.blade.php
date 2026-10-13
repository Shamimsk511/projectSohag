@extends('layouts.portal')

@section('content')
    <div class="alert alert-warning text-center my-5" role="status">
        <i class="bi bi-wifi-off fs-3 d-block mb-2" aria-hidden="true"></i>
        {{ __('app.portal.offline') }}
    </div>
@endsection
