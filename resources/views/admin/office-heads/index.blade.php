@extends('layouts.admin')

@section('subtitle', __('app.office_heads.title'))
@section('content_header_title', __('app.office_heads.title'))

@section('content_body')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-header d-flex">
            <a href="{{ route('admin.office-heads.create') }}" class="btn btn-primary ms-auto">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ __('app.office_heads.create') }}
            </a>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.office_heads.name') }}</th>
                        <th>{{ __('app.office_heads.kind') }}</th>
                        <th>{{ __('app.office_heads.status') }}</th>
                        <th class="text-end">{{ __('app.office_heads.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($heads as $head)
                        <tr>
                            <td>{{ $head->name }}</td>
                            <td>{{ __('app.transactions.types.'.$head->kind->value) }}</td>
                            <td>
                                <span @class(['badge', 'text-bg-success' => $head->isActive(), 'text-bg-secondary' => ! $head->isActive()])>
                                    {{ __('app.statuses.'.$head->status->value) }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.office-heads.edit', $head) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> {{ __('app.edit') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-body-secondary py-4">{{ __('app.office_heads.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($heads->hasPages())
            <div class="card-footer">{{ $heads->links() }}</div>
        @endif
    </div>
@stop
