@extends('layouts.admin')

@section('subtitle', __('app.closings.title'))
@section('content_header_title', __('app.closings.title'))

@section('content_body')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="row">
        <div class="col-lg-4 order-lg-2">
            @can('create', \App\Models\DailyClosing::class)
                <form method="POST" action="{{ route('admin.daily-closings.store') }}" class="card mb-3 js-confirm"
                      data-confirm="{{ json_encode([
                          'title' => __('app.assignments.confirm_title'),
                          'text' => __('app.closings.confirm'),
                          'confirm' => __('app.closings.close'),
                          'cancel' => __('app.cancel'),
                      ]) }}">
                    @csrf
                    <div class="card-header"><h3 class="card-title mb-0">{{ __('app.closings.close') }}</h3></div>
                    <div class="card-body">
                        <x-form.input name="date" type="date" :label="__('app.closings.date')" :value="$today" :max="$today" required />
                        <p class="small text-body-secondary mt-3 mb-0">{{ __('app.closings.close_hint') }}</p>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-danger w-100">
                            <i class="bi bi-lock" aria-hidden="true"></i> {{ __('app.closings.close') }}
                        </button>
                    </div>
                </form>
            @endcan
        </div>

        <div class="col-lg-8 order-lg-1">
            <div class="card">
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('app.closings.date') }}</th>
                                <th>{{ __('app.closings.closed_by') }}</th>
                                <th>{{ __('app.closings.closed_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($closings as $closing)
                                <tr>
                                    <td class="text-nowrap">{{ \App\Support\Numerals::localize($closing->date->format('d/m/Y')) }}</td>
                                    <td>{{ $closing->closedBy->name }}</td>
                                    <td class="text-nowrap">
                                        {{ \App\Support\Numerals::localize($closing->created_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i')) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-body-secondary py-4">{{ __('app.closings.empty') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($closings->hasPages())
                    <div class="card-footer">{{ $closings->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    @push('js')
        @vite('resources/js/admin/daily-closings.js')
    @endpush
@stop
