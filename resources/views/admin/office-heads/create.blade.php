@extends('layouts.admin')

@section('subtitle', __('app.office_heads.create'))
@section('content_header_title', __('app.office_heads.title'))
@section('content_header_subtitle', __('app.office_heads.create'))

@section('content_body')
    <form method="POST" action="{{ route('admin.office-heads.store') }}" class="card">
        @include('admin.office-heads._form')
    </form>
@stop
