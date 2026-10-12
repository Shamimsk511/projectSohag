{{-- Office ledger entry (disbursement, deposit, withdrawal) for an active account. --}}
@php($entryTypes = $account->account_type->entryTypes())
<form method="POST" action="{{ route('admin.accounts.transactions.store', $account) }}" class="card mb-3 js-confirm"
      data-confirm="{{ json_encode([
          'title' => __('app.transactions.confirm_title'),
          'text' => __('app.transactions.confirm_text'),
          'confirm' => __('app.transactions.confirm_yes'),
          'cancel' => __('app.cancel'),
      ]) }}">
    @csrf
    <div class="card-header"><h3 class="card-title mb-0">{{ __('app.transactions.post_entry') }}</h3></div>
    <div class="card-body">
        @error('account_id')
            <div class="alert alert-danger py-2">{{ $message }}</div>
        @enderror
        @if (count($entryTypes) === 1)
            <input type="hidden" name="type" value="{{ $entryTypes[0]->value }}">
            <p class="fw-semibold mb-3">{{ __('app.transactions.types.'.$entryTypes[0]->value) }}</p>
        @else
            <fieldset class="mb-3">
                <legend class="form-label fs-6">{{ __('app.transactions.type') }}</legend>
                <div class="btn-group w-100" role="group">
                    @foreach ($entryTypes as $entryType)
                        <input type="radio" class="btn-check" name="type" id="type-{{ $entryType->value }}" value="{{ $entryType->value }}"
                               autocomplete="off" required @checked(old('type', $entryTypes[0]->value) === $entryType->value)>
                        <label class="btn btn-outline-primary py-2" for="type-{{ $entryType->value }}">
                            {{ __('app.transactions.types.'.$entryType->value) }}
                        </label>
                    @endforeach
                </div>
                @error('type')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </fieldset>
        @endif

        <div class="mb-3">
            <x-form.input name="amount" :label="__('app.transactions.amount').' (৳)'" inputmode="decimal" autocomplete="off"
                          class="form-control-lg" required />
        </div>
        <div class="mb-3">
            <x-form.input name="transaction_date" type="date" :label="__('app.transactions.date')"
                          :value="today(config('app.display_timezone'))->toDateString()"
                          :max="today(config('app.display_timezone'))->toDateString()" required />
        </div>
        <div class="mb-3">
            <label for="payment_method" class="form-label">{{ __('app.transactions.payment_method') }}</label>
            <select id="payment_method" name="payment_method" required
                    @class(['form-select', 'is-invalid' => $errors->has('payment_method')])>
                @foreach (\App\Enums\PaymentMethod::cases() as $method)
                    <option value="{{ $method->value }}" @selected(old('payment_method', 'cash') === $method->value)>
                        {{ __('app.transactions.payment_methods.'.$method->value) }}
                    </option>
                @endforeach
            </select>
            @error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <x-form.input name="reference" :label="__('app.transactions.reference')" :hint="__('app.transactions.reference_hint')"
                          maxlength="100" autocomplete="off" />
        </div>
        <label for="description" class="form-label">{{ __('app.transactions.description') }}</label>
        <textarea id="description" name="description" rows="2" maxlength="500"
                  @class(['form-control', 'is-invalid' => $errors->has('description')])>{{ old('description') }}</textarea>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="card-footer">
        <button type="submit" class="btn btn-success btn-lg w-100">{{ __('app.transactions.submit') }}</button>
    </div>
</form>
