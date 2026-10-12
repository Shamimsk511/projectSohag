{{-- Close the account today. Expects $account, $remaining. --}}
<form method="POST" action="{{ route('admin.accounts.close', $account) }}" class="account-form js-confirm"
      data-confirm="{{ json_encode([
          'title' => __('app.assignments.confirm_title'),
          'text' => __('app.accounts.close_confirm'),
          'confirm' => __('app.accounts.confirm_yes'),
          'cancel' => __('app.cancel'),
      ]) }}">
    @csrf
    <p>{{ __('app.accounts.close_explain') }}</p>
    <div @class(['alert py-2', 'alert-success' => $remaining->isZero(), 'alert-warning' => ! $remaining->isZero()])>
        <div class="fw-semibold">{{ __('app.accounts.close_remaining', ['amount' => $remaining->format()]) }}</div>
        @unless ($remaining->isZero())
            <div class="small">{{ __('app.accounts.close_must_be_zero') }}</div>
        @endunless
    </div>
    <div class="mb-3">
        <label for="close_reason" class="form-label">{{ __('app.accounts.reason') }}</label>
        <textarea id="close_reason" name="close_reason" rows="2" maxlength="500"
                  @class(['form-control', 'is-invalid' => $errors->has('close_reason')])>{{ old('close_reason') }}</textarea>
        @error('close_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="btn btn-danger btn-lg w-100 account-submit">
        <i class="bi bi-lock" aria-hidden="true"></i> {{ __('app.accounts.actions.close') }}
    </button>
</form>
