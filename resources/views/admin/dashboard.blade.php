@extends('layouts.admin')

@section('subtitle', __('app.dashboard'))
@section('content_header_title', __('app.dashboard'))

@php
    $dayValue = $day->toDateString();
    $isToday = $dayValue === today(config('app.display_timezone'))->toDateString();
@endphp

@section('content_body')
    <form method="GET" action="{{ route('admin.dashboard') }}" class="row g-2 align-items-end mb-3">
        <div class="col-auto">
            <x-form.input name="date" type="date" :label="__('app.dash.day')" :value="$dayValue" />
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary">{{ __('app.dash.show') }}</button>
        </div>
        @can('viewAny', \App\Models\CollectionSchedule::class)
            <div class="col-auto ms-auto">
                <a class="btn btn-outline-secondary" href="{{ route('admin.schedules.index', ['date' => $dayValue]) }}">{{ __('app.dash.view_list') }}</a>
            </div>
        @endcan
    </form>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($readyToClose !== [])
        @include('admin.dashboard._ready_to_close')
    @endif

    @if ($position)
        <div class="d-flex align-items-center mb-2">
            <h2 class="h5 mb-0">{{ __('app.reports.position.title') }}</h2>
            <a class="ms-auto small" href="{{ route('admin.reports.position') }}">{{ __('app.reports.position.open') }}</a>
        </div>
        <div class="mb-4">
            @include('admin.reports._position')
        </div>
    @endif

    <div id="dashboard-live" data-live-url="{{ route('admin.dashboard.live', ['date' => $dayValue]) }}" data-live-refresh="{{ $isToday ? 1 : 0 }}">
        @include('admin.dashboard._live')
    </div>

    @push('js')
        @vite('resources/js/admin/dashboard.js')
    @endpush
@stop
