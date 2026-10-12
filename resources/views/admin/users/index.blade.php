@extends('layouts.admin')

@section('subtitle', __('app.users.title'))
@section('content_header_title', __('app.users.title'))

@section('content_body')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-header d-flex">
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary ms-auto">
                <i class="bi bi-person-plus" aria-hidden="true"></i> {{ __('app.users.create') }}
            </a>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('app.users.name') }}</th>
                        <th>{{ __('app.users.email') }}</th>
                        <th>{{ __('app.users.phone') }}</th>
                        <th>{{ __('app.users.role') }}</th>
                        <th>{{ __('app.users.status') }}</th>
                        <th class="text-end">{{ __('app.users.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{{ $user->phone }}</td>
                            <td>{{ $user->currentRole() ? __('app.roles.'.$user->currentRole()->value) : '' }}</td>
                            <td>
                                <span @class(['badge', 'text-bg-success' => $user->isActive(), 'text-bg-secondary' => ! $user->isActive()])>
                                    {{ __('app.statuses.'.$user->status->value) }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> {{ __('app.edit') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">{{ __('app.users.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="card-footer">{{ $users->links() }}</div>
        @endif
    </div>
@stop
