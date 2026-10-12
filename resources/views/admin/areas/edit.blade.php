@extends('layouts.admin')

@section('subtitle', __('app.areas.edit'))
@section('content_header_title', __('app.areas.title'))
@section('content_header_subtitle', __('app.areas.edit'))

@section('content_body')
    <form method="POST" action="{{ route('admin.areas.update', $area) }}" class="card">
        @method('PUT')
        @include('admin.areas._form')
    </form>
@stop
