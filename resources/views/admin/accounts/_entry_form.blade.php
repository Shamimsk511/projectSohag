{{-- Office ledger entry (deposit, withdrawal, opening, agreed credit, disbursement) for an active account. Expects $account, $entryTypes. --}}
@php
    $today = today(config('app.display_timezone'))->toDateString();
    $selected = old('type', $entryTypes[0]->value);
    $typeStrings = collect($entryTypes)->mapWithKeys(fn (\App\Enums\TransactionType $type) => [$type->value => [
        'amount' => __('app.transactions.amount_questions.'.$type->value),
        'submit' => __('app.transactions.submit_as.'.$type->value),
        'money' => $type->movesMoney(),
        'schedule' => $type === $account->account_type->collectionType(),
    ]]);
    $current = $typeStrings[$selected] ?? $typeStrings->first();
@endphp
<form method="POST" action="{{ route('admin.accounts.transactions.store', $account) }}" class="account-form js-confirm js-entry-form"
      data-types="{{ $typeStrings->toJson() }}"
      data-confirm="{{ json_encode([
          'title' => __('app.transactions.confirm_title'),
          'text' => __('app.transactions.confirm_text'),
          'confirm' => __('app.transactions.confirm_yes'),
          'cancel' => __('app.cancel'),
      ]) }}">
    @csrf
    @error('account_id')
        <div class="alert alert-danger py-2">{{ $message }}</div>
    @enderror

    @if (count($entryTypes) === 1)
        <input type="hidden" name="type" value="{{ $entryTypes[0]->value }}">
    @else
        <fieldset class="mb-3">
            <legend class="form-label fs-6 required">{{ __('app.transactions.type') }}</legend>
            <div class="row g-2">
                @foreach ($entryTypes as $entryType)
                    <div class="col-6">
                        <input type="radio" class="btn-check" name="type" id="type-{{ $entryType->value }}" value="{{ $entryType->value }}"
                               autocomplete="off" required @checked($selected === $entryType->value)>
                        <label class="btn btn-outline-success account-choice w-100 h-100" for="type-{{ $entryType->value }}">
                            {{ __('app.transactions.types.'.$entryType->value) }}
                        </label>
                    </div>
                @endforeach
            </div>
            @error('type')<div class="text-danger mt-1">{{ $message }}</div>@enderror
            @foreach ($entryTypes as $entryType)
                @if (\Illuminate\Support\Facades\Lang::has('app.transactions.type_hints.'.$entryType->value))
                    <div class="form-text" data-type-hint="{{ $entryType->value }}" @if ($selected !== $entryType->value) hidden @endif>
                        <i class="bi bi-info-circle" aria-hidden="true"></i> {{ __('app.transactions.type_hints.'.$entryType->value) }}
                    </div>
                @endif
            @endforeach
        </fieldset>
    @endif

    <div class="mb-3">
        <label for="amount" class="form-label fw-semibold required" data-amount-label>{{ $current['amount'] }}</label>
        <div class="input-group input-group-lg">
            <span class="input-group-text">৳</span>
            <input type="text" id="amount" name="amount" value="{{ $errors->hasBag('officeIncome') ? '' : old('amount') }}" inputmode="decimal" autocomplete="off" required
                   @class(['form-control account-amount-input', 'is-invalid' => $errors->has('amount')])>
            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6">
            <x-form.input name="transaction_date" type="date" :label="__('app.transactions.date')" :value="$today" :max="$today"
                          class="form-control-lg" required />
        </div>
    </div>

    @if ($openSchedules->isNotEmpty())
        <div class="mb-3" data-schedule-only @if (! $current['schedule']) hidden @endif>
            <label for="schedule_id" class="form-label fw-semibold">{{ __('app.transactions.schedule') }}</label>
            <select id="schedule_id" name="schedule_id" @class(['form-select', 'is-invalid' => $errors->has('schedule_id')]) @if (! $current['schedule']) disabled @endif>
                <option value="">{{ __('app.transactions.schedule_none') }}</option>
                @foreach ($openSchedules as $row)
                    <option value="{{ $row->id }}" @selected((string) old('schedule_id') === (string) $row->id)>
                        {{ \App\Support\Numerals::localize($row->due_date->format('d/m/Y')) }} · {{ $row->expected_amount->format() }}
                    </option>
                @endforeach
            </select>
            @error('schedule_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    @endif

    <fieldset class="mb-3" data-money-only @if (! $current['money']) hidden disabled @endif>
        <legend class="form-label fs-6 required">{{ __('app.transactions.payment_method') }}</legend>
        <div class="row g-2">
            @foreach (\App\Enums\PaymentMethod::cases() as $method)
                <div class="col-4">
                    <input type="radio" class="btn-check" name="payment_method" id="method-{{ $method->value }}" value="{{ $method->value }}"
                           autocomplete="off" required @checked(old('payment_method', 'cash') === $method->value)>
                    <label class="btn btn-outline-secondary account-choice w-100 h-100" for="method-{{ $method->value }}">
                        {{ __('app.transactions.payment_methods.'.$method->value) }}
                    </label>
                </div>
            @endforeach
        </div>
        @error('payment_method')<div class="text-danger mt-1">{{ $message }}</div>@enderror
    </fieldset>
    {{-- Book entries move no money; the server stores no method for them, this keeps the request valid. --}}
    <input type="hidden" name="payment_method" value="cash" data-money-fallback @if ($current['money']) disabled @endif>

    <details class="mb-3" @if ($errors->hasAny(['reference', 'description'])) open @endif>
        <summary class="fw-semibold text-primary py-2">{{ __('app.accounts.more_details') }}</summary>
        <div class="pt-2">
            <div class="mb-3">
                <x-form.input name="reference" :label="__('app.transactions.reference')" :hint="__('app.transactions.reference_hint')"
                              maxlength="100" autocomplete="off" />
            </div>
            <label for="description" class="form-label">{{ __('app.transactions.description') }}</label>
            <textarea id="description" name="description" rows="2" maxlength="500"
                      @class(['form-control', 'is-invalid' => $errors->has('description')])>{{ old('description') }}</textarea>
            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </details>

    <button type="submit" class="btn btn-success btn-lg w-100 account-submit">
        <i class="bi bi-save" aria-hidden="true"></i> <span data-submit-label>{{ $current['submit'] }}</span>
    </button>
</form>
