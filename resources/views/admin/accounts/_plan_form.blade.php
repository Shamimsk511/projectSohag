{{-- Create or replace the account's collection plan. Expects $account. --}}
@php
    $plan = $account->collectionPlan;
    $today = today(config('app.display_timezone'))->toDateString();
    $frequency = old('frequency', $plan?->frequency->value ?? \App\Enums\PlanFrequency::Daily->value);
    $weekdays = array_map('intval', (array) old('weekdays', $plan?->weekdays ?? \App\Models\CollectionPlan::DEFAULT_WEEKDAYS));
    $startsOn = $plan && $plan->starts_on->toDateString() >= $today ? $plan->starts_on->toDateString() : $today;
@endphp
<form method="POST" action="{{ route('admin.accounts.collection-plan.update', $account) }}" class="account-form js-plan-form js-once">
    @csrf
    @method('PUT')

    <fieldset class="account-group mb-3">
        <legend class="account-group__title">1. {{ __('app.schedules.plan_amount_group') }}</legend>
        <div class="mb-3">
            <x-form.input name="installment_amount" :label="__('app.schedules.installment_amount').' (৳)'"
                          :value="$plan?->installment_amount->toTaka()" inputmode="decimal" autocomplete="off"
                          class="form-control-lg" required />
        </div>
        <div class="mb-3">
            <label for="frequency" class="form-label required">{{ __('app.schedules.frequency') }}</label>
            <select id="frequency" name="frequency" @class(['form-select form-select-lg', 'is-invalid' => $errors->has('frequency')])>
                @foreach (\App\Enums\PlanFrequency::cases() as $case)
                    <option value="{{ $case->value }}" @selected($frequency === $case->value)>{{ __('app.schedules.frequencies.'.$case->value) }}</option>
                @endforeach
            </select>
            @error('frequency')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @foreach (\App\Enums\PlanFrequency::cases() as $case)
                <div class="form-text" data-frequency-hint="{{ $case->value }}" @if ($frequency !== $case->value) hidden @endif>
                    <i class="bi bi-info-circle" aria-hidden="true"></i> {{ __('app.schedules.frequency_hints.'.$case->value) }}
                </div>
            @endforeach
        </div>
        <fieldset data-daily-only>
            <legend class="form-label fs-6 required">{{ __('app.schedules.weekdays') }}</legend>
            <div class="d-flex flex-wrap gap-2">
                @foreach ([6, 0, 1, 2, 3, 4, 5] as $day)
                    <input class="btn-check" type="checkbox" name="weekdays[]" value="{{ $day }}" id="weekday-{{ $day }}" autocomplete="off"
                           @checked(in_array($day, $weekdays, true))>
                    <label class="btn btn-outline-primary account-day" for="weekday-{{ $day }}">{{ __('app.schedules.weekday_names')[$day] }}</label>
                @endforeach
            </div>
            @error('weekdays')<div class="text-danger mt-1">{{ $message }}</div>@enderror
        </fieldset>
    </fieldset>

    <fieldset class="account-group mb-3">
        <legend class="account-group__title">2. {{ __('app.schedules.plan_time_group') }}</legend>
        <div class="mb-3">
            <x-form.input name="starts_on" type="date" :label="__('app.schedules.starts_on')" :value="$startsOn" :min="$today" required />
        </div>
        <div class="row g-2 mb-1">
            <div class="col-6">
                <x-form.input name="duration" type="number" :label="__('app.schedules.duration')" min="1" max="600" step="1"
                              inputmode="numeric" autocomplete="off" />
            </div>
            <div class="col-6">
                <label for="duration_unit" class="form-label">{{ __('app.schedules.duration_unit') }}</label>
                <select id="duration_unit" name="duration_unit" @class(['form-select', 'is-invalid' => $errors->has('duration_unit')])>
                    @foreach (__('app.schedules.duration_units') as $unit => $unitLabel)
                        <option value="{{ $unit }}" @selected(old('duration_unit', 'years') === $unit)>{{ $unitLabel }}</option>
                    @endforeach
                </select>
                @error('duration_unit')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="form-text mb-3">{{ __('app.schedules.duration_hint') }}</div>
        <x-form.input name="ends_on" type="date" :label="__('app.schedules.ends_on')" :value="$plan?->ends_on?->toDateString()"
                      :min="$today" :hint="__('app.schedules.ends_on_hint')" />
    </fieldset>

    <div class="alert alert-info py-2" data-plan-estimate hidden>
        {{ __('app.schedules.estimated_total') }}: <span class="fw-semibold fs-5" data-plan-estimate-value></span>
        <div class="small">{{ __('app.schedules.estimated_total_hint') }}</div>
    </div>
    <p class="small text-body-secondary">{{ __('app.schedules.plan_hint') }}</p>

    <button type="submit" class="btn btn-primary btn-lg w-100 account-submit">
        <i class="bi bi-save" aria-hidden="true"></i> {{ __('app.schedules.save_plan') }}
    </button>
</form>
