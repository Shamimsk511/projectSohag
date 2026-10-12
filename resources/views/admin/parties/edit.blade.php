@extends('layouts.admin')

@section('subtitle', __('app.parties.edit'))
@section('content_header_title', __('app.parties.title'))
@section('content_header_subtitle', __('app.parties.edit'))

@section('content_body')
    <form method="POST" action="{{ route('admin.parties.update', $party) }}" class="card">
        @method('PUT')
        @include('admin.parties._form')
    </form>
@stop
