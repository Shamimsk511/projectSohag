{{-- Create or replace the account's collection plan. Expects $account. --}}
@php
    $plan = $account->collectionPlan;
    $today = today(config('app.display_timezone'))->toDateString();
    $frequency = old('frequency', $plan?->frequency->value ?? \App\Enums\PlanFrequency::Daily->value);
    $weekdays = array_map('intval', (array) old('weekdays', $plan?->weekdays ?? \App\Models\CollectionPlan::DEFAULT_WEEKDAYS));
    $startsOn = $plan && $plan->starts_on->toDateString() >= $today ? $plan->starts_on->toDateString() : $today;
@endphp
<form method="POST" action="{{ route('admin.accounts.collection-plan.update', $account) }}" class="card mb-3 js-plan-form">
    @csrf
    @method('PUT')
    <div class="card-header"><h3 class="card-title mb-0">{{ __('app.schedules.plan') }}</h3></div>
    <div class="card-body">
        <div class="mb-3">
            <label for="frequency" class="form-label">{{ __('app.schedules.frequency') }}</label>
            <select id="frequency" name="frequency" @class(['form-select', 'is-invalid' => $errors->has('frequency')])>
                @foreach (\App\Enums\PlanFrequency::cases() as $case)
                    <option value="{{ $case->value }}" @selected($frequency === $case->value)>{{ __('app.schedules.frequencies.'.$case->value) }}</option>
                @endforeach
            </select>
            @error('frequency')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <x-form.input name="installment_amount" :label="__('app.schedules.installment_amount').' (৳)'"
                          :value="$plan?->installment_amount->toTaka()" inputmode="decimal" autocomplete="off" required />
        </div>
        <fieldset class="mb-3" data-daily-only>
            <legend class="form-label fs-6">{{ __('app.schedules.weekdays') }}</legend>
            @foreach ([6, 0, 1, 2, 3, 4, 5] as $day)
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="weekdays[]" value="{{ $day }}" id="weekday-{{ $day }}"
                           @checked(in_array($day, $weekdays, true))>
                    <label class="form-check-label" for="weekday-{{ $day }}">{{ __('app.schedules.weekday_names')[$day] }}</label>
                </div>
            @endforeach
            @error('weekdays')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </fieldset>
        <div class="mb-3">
            <x-form.input name="starts_on" type="date" :label="__('app.schedules.starts_on')" :value="$startsOn" :min="$today" required />
        </div>
        <x-form.input name="ends_on" type="date" :label="__('app.schedules.ends_on')" :value="$plan?->ends_on?->toDateString()"
                      :min="$today" :hint="__('app.schedules.ends_on_hint')" />
        <p class="small text-body-secondary mt-3 mb-0">{{ __('app.schedules.plan_hint') }}</p>
    </div>
    <div class="card-footer">
        <button type="submit" class="btn btn-primary w-100">{{ __('app.schedules.save_plan') }}</button>
    </div>
</form>
