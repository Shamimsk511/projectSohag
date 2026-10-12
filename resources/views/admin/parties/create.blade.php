@extends('layouts.admin')

@section('subtitle', __('app.parties.create'))
@section('content_header_title', __('app.parties.title'))
@section('content_header_subtitle', __('app.parties.create'))

@section('content_body')
    <form method="POST" action="{{ route('admin.parties.store') }}" class="card">
        @include('admin.parties._form')
    </form>
@stop
