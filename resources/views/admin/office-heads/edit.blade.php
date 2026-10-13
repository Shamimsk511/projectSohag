@extends('layouts.admin')

@section('subtitle', __('app.office_heads.edit'))
@section('content_header_title', __('app.office_heads.title'))
@section('content_header_subtitle', __('app.office_heads.edit'))

@section('content_body')
    <form method="POST" action="{{ route('admin.office-heads.update', $head) }}" class="card">
        @method('PUT')
        @include('admin.office-heads._form')
    </form>
@stop
