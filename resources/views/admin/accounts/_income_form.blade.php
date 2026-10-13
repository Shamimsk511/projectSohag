{{-- Office income taken from this client (fee, form...), linked to the account. Expects $account, $incomeHeads. Errors live in the officeIncome bag. --}}
@php
    $today = today(config('app.display_timezone'))->toDateString();
    $bag = $errors->officeIncome;
@endphp
<form method="POST" action="{{ route('admin.accounts.office-income.store', $account) }}" class="account-form js-confirm js-once"
      data-confirm="{{ json_encode([
          'title' => __('app.transactions.confirm_title'),
          'text' => __('app.transactions.confirm_text'),
          'confirm' => __('app.transactions.confirm_yes'),
          'cancel' => __('app.cancel'),
      ]) }}">
    @csrf
    <p class="text-body-secondary small">{{ __('app.office_heads.client_income_hint') }}</p>
    @if ($bag->has('account_id'))
        <div class="alert alert-danger py-2">{{ $bag->first('account_id') }}</div>
    @endif

    <div class="mb-3">
        <label for="income_head" class="form-label fw-semibold required">{{ __('app.office_heads.head') }}</label>
        <select id="income_head" name="office_head_id" required @class(['form-select', 'form-select-lg', 'is-invalid' => $bag->has('office_head_id')])>
            <option value="">—</option>
            @foreach ($incomeHeads as $head)
                <option value="{{ $head->id }}" @selected((string) ($bag->any() ? old('office_head_id') : '') === (string) $head->id)>{{ $head->name }}</option>
            @endforeach
        </select>
        @if ($bag->has('office_head_id'))<div class="invalid-feedback">{{ $bag->first('office_head_id') }}</div>@endif
    </div>

    <div class="mb-3">
        <label for="income_amount" class="form-label fw-semibold required">{{ __('app.transactions.amount') }}</label>
        <div class="input-group input-group-lg">
            <span class="input-group-text">৳</span>
            <input type="text" id="income_amount" name="amount" value="{{ $bag->any() ? old('amount') : '' }}" inputmode="decimal" autocomplete="off" required
                   @class(['form-control account-amount-input', 'is-invalid' => $bag->has('amount')])>
            @if ($bag->has('amount'))<div class="invalid-feedback">{{ $bag->first('amount') }}</div>@endif
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6">
            <label for="income_date" class="form-label fw-semibold required">{{ __('app.transactions.date') }}</label>
            <input type="date" id="income_date" name="transaction_date" value="{{ $bag->any() ? old('transaction_date', $today) : $today }}" max="{{ $today }}" required
                   @class(['form-control', 'form-control-lg', 'is-invalid' => $bag->has('transaction_date')])>
            @if ($bag->has('transaction_date'))<div class="invalid-feedback">{{ $bag->first('transaction_date') }}</div>@endif
        </div>
        <div class="col-sm-6">
            <label for="income_method" class="form-label fw-semibold required">{{ __('app.transactions.payment_method') }}</label>
            <select id="income_method" name="payment_method" required @class(['form-select', 'form-select-lg', 'is-invalid' => $bag->has('payment_method')])>
                @foreach (\App\Enums\PaymentMethod::cases() as $method)
                    <option value="{{ $method->value }}" @selected(($bag->any() ? old('payment_method', 'cash') : 'cash') === $method->value)>{{ __('app.transactions.payment_methods.'.$method->value) }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="mb-3">
        <label for="income_description" class="form-label">{{ __('app.transactions.description') }}</label>
        <textarea id="income_description" name="description" rows="2" maxlength="500" class="form-control">{{ $bag->any() ? old('description') : '' }}</textarea>
    </div>

    <button type="submit" class="btn btn-success btn-lg w-100 account-submit">
        <i class="bi bi-save" aria-hidden="true"></i> {{ __('app.office_heads.submit') }}
    </button>
</form>
