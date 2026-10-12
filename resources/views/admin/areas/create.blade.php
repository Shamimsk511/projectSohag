@extends('layouts.admin')

@section('subtitle', __('app.areas.create'))
@section('content_header_title', __('app.areas.title'))
@section('content_header_subtitle', __('app.areas.create'))

@section('content_body')
    <form method="POST" action="{{ route('admin.areas.store') }}" class="card">
        @include('admin.areas._form')
    </form>
@stop
