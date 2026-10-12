{{-- Set or change the loan contract amount / saving agreed amount, with a reason. Expects $account. --}}
<form method="POST" action="{{ route('admin.accounts.adjust', $account) }}" class="account-form js-once">
    @csrf
    <div class="mb-3">
        <label for="contract_amount" class="form-label required">
            {{ __($account->isLoan() ? 'app.accounts.new_contract_amount' : 'app.accounts.new_agreed_amount') }}
        </label>
        <div class="input-group input-group-lg">
            <span class="input-group-text">৳</span>
            <input type="text" id="contract_amount" name="contract_amount" value="{{ old('contract_amount') }}" inputmode="decimal"
                   autocomplete="off" required @class(['form-control', 'is-invalid' => $errors->has('contract_amount')])
                   @unless ($account->isLoan()) aria-describedby="contract_amount-hint" @endunless>
            @error('contract_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @unless ($account->isLoan())
            <div id="contract_amount-hint" class="form-text">{{ __('app.accounts.agreed_amount_hint') }}</div>
        @endunless
    </div>
    <div class="mb-3">
        <label for="adjust-reason" class="form-label required">{{ __('app.accounts.reason') }}</label>
        <textarea id="adjust-reason" name="reason" rows="2" maxlength="500" required
                  @class(['form-control', 'is-invalid' => $errors->has('reason')])>{{ old('reason') }}</textarea>
        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="btn btn-secondary btn-lg w-100 account-submit">
        <i class="bi bi-save" aria-hidden="true"></i> {{ __('app.accounts.adjust_submit') }}
    </button>
</form>
