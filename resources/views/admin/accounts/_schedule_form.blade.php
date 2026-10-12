{{-- One-off collection date outside the plan. Expects $account. --}}
@php($today = today(config('app.display_timezone'))->toDateString())
<form method="POST" action="{{ route('admin.accounts.schedules.store', $account) }}" class="card mb-3">
    @csrf
    <div class="card-header"><h3 class="card-title mb-0">{{ __('app.schedules.add') }}</h3></div>
    <div class="card-body">
        <div class="mb-3">
            <x-form.input name="due_date" type="date" :label="__('app.schedules.due_date')" :value="$today" :min="$today" required />
        </div>
        <div class="mb-3">
            <x-form.input name="expected_amount" :label="__('app.schedules.expected_amount').' (৳)'"
                          inputmode="decimal" autocomplete="off" required />
        </div>
        <label for="schedule-notes" class="form-label">{{ __('app.schedules.notes') }}</label>
        <textarea id="schedule-notes" name="notes" rows="2" maxlength="500"
                  @class(['form-control', 'is-invalid' => $errors->has('notes')])>{{ old('notes') }}</textarea>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <p class="small text-body-secondary mt-2 mb-0">{{ __('app.schedules.add_hint') }}</p>
    </div>
    <div class="card-footer">
        <button type="submit" class="btn btn-primary w-100">{{ __('app.schedules.add') }}</button>
    </div>
</form>
