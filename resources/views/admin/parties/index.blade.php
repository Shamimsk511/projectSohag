@extends('layouts.admin')

@section('subtitle', __('app.parties.title'))
@section('content_header_title', __('app.parties.title'))

@section('content_body')
    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <select id="filter-area" class="form-select w-auto" aria-label="{{ __('app.parties.area') }}">
                <option value="">{{ __('app.parties.all_areas') }}</option>
                @foreach ($areas as $area)
                    <option value="{{ $area->id }}">{{ $area->name }}</option>
                @endforeach
            </select>
            <select id="filter-status" class="form-select w-auto" aria-label="{{ __('app.parties.status') }}">
                <option value="">{{ __('app.parties.all_statuses') }}</option>
                @foreach (\App\Enums\ActiveStatus::cases() as $status)
                    <option value="{{ $status->value }}">{{ __('app.statuses.'.$status->value) }}</option>
                @endforeach
            </select>
            @can('create', \App\Models\Party::class)
                <a href="{{ route('admin.parties.create') }}" class="btn btn-primary ms-auto">
                    <i class="bi bi-person-plus" aria-hidden="true"></i> {{ __('app.parties.create') }}
                </a>
            @endcan
        </div>
        <div class="card-body">
            {{-- Column order must match App\Services\PartyTableQuery::ORDERABLE. --}}
            <table id="parties-table" class="table table-hover align-middle w-100"
                data-source="{{ route('admin.parties.data') }}"
                data-strings='@json(__('app.datatable'))'
                data-action-labels='@json(['view' => __('app.parties.view'), 'edit' => __('app.edit')])'>
                <thead>
                    <tr>
                        <th>{{ __('app.parties.code') }}</th>
                        <th>{{ __('app.parties.name') }}</th>
                        <th>{{ __('app.parties.phone') }}</th>
                        <th>{{ __('app.parties.area') }}</th>
                        <th>{{ __('app.parties.status') }}</th>
                        <th class="text-end">{{ __('app.parties.actions') }}</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
@stop

@push('js')
    @vite('resources/js/admin/parties.js')
@endpush
