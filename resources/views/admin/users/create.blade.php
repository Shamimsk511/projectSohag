@extends('layouts.admin')

@section('subtitle', __('app.users.create'))
@section('content_header_title', __('app.users.title'))
@section('content_header_subtitle', __('app.users.create'))

@section('content_body')
    <form method="POST" action="{{ route('admin.users.store') }}" class="card">
        @include('admin.users._form')
    </form>
@stop
