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

    <div id="dashboard-live" data-live-url="{{ route('admin.dashboard.live', ['date' => $dayValue]) }}" data-live-refresh="{{ $isToday ? 1 : 0 }}">
        @include('admin.dashboard._live')
    </div>

    @push('js')
        @vite('resources/js/admin/dashboard.js')
    @endpush
@stop
