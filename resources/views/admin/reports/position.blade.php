@extends('layouts.admin')

@section('subtitle', __('app.reports.position.title'))
@section('content_header_title', __('app.reports.position.title'))

@section('content_body')
    <p class="text-body-secondary">
        {{ __('app.reports.position.as_of', ['date' => \App\Support\Numerals::localize(today(config('app.display_timezone'))->format('d/m/Y'))]) }}
    </p>
    @include('admin.reports._position')
@stop
