@extends('layouts.admin')

@section('subtitle', __('app.users.edit'))
@section('content_header_title', __('app.users.title'))
@section('content_header_subtitle', __('app.users.edit'))

@section('content_body')
    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="card">
        @method('PUT')
        @include('admin.users._form')
    </form>
@stop
