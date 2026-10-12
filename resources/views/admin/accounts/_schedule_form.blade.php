{{-- One-off collection date outside the plan. Expects $account. --}}
@php($today = today(config('app.display_timezone'))->toDateString())
<form method="POST" action="{{ route('admin.accounts.schedules.store', $account) }}" class="account-form js-once">
    @csrf
    <p class="text-body-secondary">{{ __('app.schedules.add_hint') }}</p>
    <div class="mb-3">
        <x-form.input name="due_date" type="date" :label="__('app.schedules.due_date')" :value="$today" :min="$today"
                      class="form-control-lg" required />
    </div>
    <div class="mb-3">
        <label for="expected_amount" class="form-label required">{{ __('app.schedules.expected_amount') }}</label>
        <div class="input-group input-group-lg">
            <span class="input-group-text">৳</span>
            <input type="text" id="expected_amount" name="expected_amount" value="{{ old('expected_amount') }}" inputmode="decimal"
                   autocomplete="off" required @class(['form-control', 'is-invalid' => $errors->has('expected_amount')])>
            @error('expected_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="mb-3">
        <label for="schedule-notes" class="form-label">{{ __('app.schedules.notes') }} <span class="text-body-secondary">({{ __('app.accounts.optional') }})</span></label>
        <textarea id="schedule-notes" name="notes" rows="2" maxlength="500"
                  @class(['form-control', 'is-invalid' => $errors->has('notes')])>{{ old('notes') }}</textarea>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="btn btn-primary btn-lg w-100 account-submit">
        <i class="bi bi-calendar-plus" aria-hidden="true"></i> {{ __('app.schedules.add') }}
    </button>
</form>
