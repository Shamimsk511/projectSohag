@extends('layouts.admin')

@section('subtitle', __('app.holidays.title'))
@section('content_header_title', __('app.holidays.title'))

@section('content_body')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="row">
        <div class="col-lg-4 order-lg-2">
            <form method="POST" action="{{ route('admin.holidays.store') }}" class="card mb-3">
                @csrf
                <div class="card-header"><h3 class="card-title mb-0">{{ __('app.holidays.add') }}</h3></div>
                <div class="card-body">
                    <div class="mb-3">
                        <x-form.input name="date" type="date" :label="__('app.holidays.date')" :min="$today" required />
                    </div>
                    <x-form.input name="name" :label="__('app.holidays.name')" :hint="__('app.holidays.name_hint')" maxlength="100" required />
                    <p class="small text-body-secondary mt-3 mb-0">{{ __('app.holidays.hint') }}</p>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary w-100">{{ __('app.holidays.add') }}</button>
                </div>
            </form>
        </div>

        <div class="col-lg-8 order-lg-1">
            <div class="card">
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('app.holidays.date') }}</th>
                                <th>{{ __('app.holidays.name') }}</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($holidays as $holiday)
                                <tr>
                                    <td class="text-nowrap">
                                        {{ \App\Support\Numerals::localize($holiday->date->format('d/m/Y')) }}
                                        <span class="text-body-secondary small">({{ __('app.schedules.weekday_names')[$holiday->date->dayOfWeek] }})</span>
                                    </td>
                                    <td>{{ $holiday->name }}</td>
                                    <td class="text-end">
                                        @if ($holiday->date->toDateString() > $today)
                                            <form method="POST" action="{{ route('admin.holidays.destroy', $holiday) }}" class="js-confirm"
                                                  data-confirm="{{ json_encode([
                                                      'title' => __('app.assignments.confirm_title'),
                                                      'text' => __('app.holidays.remove_confirm'),
                                                      'confirm' => __('app.holidays.remove'),
                                                      'cancel' => __('app.cancel'),
                                                  ]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash" aria-hidden="true"></i> {{ __('app.holidays.remove') }}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-body-secondary py-4">{{ __('app.holidays.empty') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($holidays->hasPages())
                    <div class="card-footer">{{ $holidays->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    @push('js')
        @vite('resources/js/admin/holidays.js')
    @endpush
@stop
