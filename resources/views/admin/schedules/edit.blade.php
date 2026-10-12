@extends('layouts.admin')

@section('subtitle', __('app.schedules.edit'))
@section('content_header_title', __('app.schedules.title'))
@section('content_header_subtitle', $schedule->account->account_number)

@section('content_body')
    @php($today = today(config('app.display_timezone'))->toDateString())

    <div class="row">
        <div class="col-lg-6">
            <form method="POST" action="{{ route('admin.schedules.update', $schedule) }}" class="card mb-3">
                @csrf
                @method('PUT')
                <div class="card-header">
                    <h2 class="card-title mb-0">{{ __('app.schedules.edit') }}</h2>
                </div>
                <div class="card-body">
                    <p>
                        <a href="{{ route('admin.parties.show', $schedule->party) }}">{{ $schedule->party->name }}</a>
                        ({{ $schedule->party->party_code }}) ·
                        <a href="{{ route('admin.accounts.show', $schedule->account) }}">{{ $schedule->account->account_number }}</a>
                        · {{ $schedule->fieldOfficer?->name ?? __('app.schedules.unassigned') }}
                    </p>
                    <div class="mb-3">
                        <x-form.input name="due_date" type="date" :label="__('app.schedules.due_date')"
                                      :value="$schedule->due_date->toDateString()" :min="$today" required />
                    </div>
                    <div class="mb-3">
                        <x-form.input name="expected_amount" :label="__('app.schedules.expected_amount').' (৳)'"
                                      :value="$schedule->expected_amount->toTaka()" inputmode="decimal" autocomplete="off" required />
                    </div>
                    <label for="notes" class="form-label">{{ __('app.schedules.notes') }}</label>
                    <textarea id="notes" name="notes" rows="2" maxlength="500"
                              @class(['form-control', 'is-invalid' => $errors->has('notes')])>{{ old('notes', $schedule->notes) }}</textarea>
                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    @error('status')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary w-100">{{ __('app.save') }}</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.schedules.cancel', $schedule) }}" class="js-confirm"
                  data-confirm="{{ json_encode([
                      'title' => __('app.schedules.cancel'),
                      'text' => __('app.schedules.cancel_text'),
                      'input' => __('app.schedules.cancel_reason'),
                      'confirm' => __('app.schedules.cancel'),
                      'cancel' => __('app.cancel'),
                  ]) }}">
                @csrf
                <input type="hidden" name="reason">
                <button type="submit" class="btn btn-outline-danger w-100">
                    <i class="bi bi-x-circle" aria-hidden="true"></i> {{ __('app.schedules.cancel') }}
                </button>
                @error('reason')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
            </form>
        </div>
    </div>

    @push('js')
        @vite('resources/js/admin/schedules.js')
    @endpush
@stop
