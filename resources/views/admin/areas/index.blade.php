@extends('layouts.admin')

@section('subtitle', __('app.areas.title'))
@section('content_header_title', __('app.areas.title'))

@section('content_body')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-header d-flex">
            <a href="{{ route('admin.areas.create') }}" class="btn btn-primary ms-auto">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ __('app.areas.create') }}
            </a>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.areas.name') }}</th>
                        <th>{{ __('app.areas.parties_count') }}</th>
                        <th>{{ __('app.areas.status') }}</th>
                        <th class="text-end">{{ __('app.areas.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($areas as $area)
                        <tr>
                            <td>{{ $area->name }}</td>
                            <td>{{ \App\Support\Numerals::localize((string) $area->parties_count) }}</td>
                            <td>
                                <span @class(['badge', 'text-bg-success' => $area->isActive(), 'text-bg-secondary' => ! $area->isActive()])>
                                    {{ __('app.statuses.'.$area->status->value) }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.areas.edit', $area) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> {{ __('app.edit') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-body-secondary py-4">{{ __('app.areas.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($areas->hasPages())
            <div class="card-footer">{{ $areas->links() }}</div>
        @endif
    </div>
@stop
