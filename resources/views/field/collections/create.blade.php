@extends('layouts.field')

@section('title', __('app.field.collect_title'))

@section('content')
    @include('field._member_header', ['party' => $schedule->party])

    <dl class="row bg-body border rounded py-2 mx-0 mb-3">
        <dt class="col-6 fw-normal field-amount-label align-self-center">{{ __('app.field.expected') }}</dt>
        <dd class="col-6 text-end field-amount mb-1">{{ $schedule->expected_amount->format() }}</dd>
        <dt class="col-6 fw-normal field-amount-label align-self-center">{{ __('app.field.collected_so_far') }}</dt>
        <dd class="col-6 text-end field-amount mb-1">{{ $collected->format() }}</dd>
        <dt class="col-6 fw-normal field-amount-label align-self-center">{{ $schedule->account->isLoan() ? __('app.accounts.outstanding') : ($schedule->account->isFdr() ? __('app.accounts.remaining_payback') : __('app.accounts.balance')) }}</dt>
        <dd class="col-6 text-end field-amount mb-1">{{ $accountRemaining->format() }}</dd>
    </dl>

    {{-- .js-queue-collect: field.js sends it through the offline queue (docs/PWA.md); plain POST without JS. --}}
    <div class="alert alert-danger d-none" role="alert" data-queue-error></div>
    <form method="POST" action="{{ route('field.collections.store', $schedule) }}" class="js-queue-collect js-two-step"
          data-schedule-id="{{ $schedule->id }}" data-account-id="{{ $schedule->account_id }}"
          data-party-name="{{ $schedule->party->name }}" data-account-number="{{ $schedule->account->account_number }}">
        @csrf
        <input type="hidden" name="client_uuid" value="{{ old('client_uuid', $clientUuid) }}">

        <div class="mb-3">
            {{-- Prefilled with the full due; the keypad opens only when the officer taps the box to change it. --}}
            <x-form.input name="amount" :label="__('app.transactions.amount')" :value="$remaining->isPositive() ? $remaining->toTaka() : null"
                inputmode="decimal" autocomplete="off" required class="form-control-lg fs-3 fw-bold" />
        </div>

        <div class="mb-3">
            <label for="payment_method" class="form-label">{{ __('app.transactions.payment_method') }}</label>
            <select id="payment_method" name="payment_method" @class(['form-select', 'is-invalid' => $errors->has('payment_method')])>
                @foreach (\App\Enums\PaymentMethod::cases() as $method)
                    <option value="{{ $method->value }}" @selected(old('payment_method', 'cash') === $method->value)>
                        {{ __('app.transactions.payment_methods.'.$method->value) }}
                    </option>
                @endforeach
            </select>
            @error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label for="description" class="form-label">{{ __('app.transactions.description') }}</label>
            <textarea id="description" name="description" rows="2" maxlength="500"
                @class(['form-control', 'is-invalid' => $errors->has('description')])>{{ old('description') }}</textarea>
            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        @error('schedule_id')<div class="alert alert-danger">{{ $message }}</div>@enderror
        @error('account_id')<div class="alert alert-danger">{{ $message }}</div>@enderror
        @error('transaction_date')<div class="alert alert-danger">{{ $message }}</div>@enderror

        {{-- Two steps (.js-two-step, field.js): "Collect" shows the amount, then "Save collection" posts.
             Without JS the step button stays hidden and the save button works as a plain form. --}}
        <button type="button" class="btn btn-primary btn-lg w-100 fw-semibold d-none" data-step-start>
            <i class="bi bi-cash-coin" aria-hidden="true"></i> {{ __('app.field.collect') }}
        </button>
        <div data-step-confirm>
            <div class="alert alert-success text-center d-none" data-step-summary>
                <div>{{ __('app.field.confirm_amount') }}</div>
                <div class="field-amount fs-2" data-step-amount></div>
                <div class="small">{{ __('app.field.cannot_undo') }}</div>
            </div>
            <button type="submit" class="btn btn-success btn-lg w-100 fw-semibold">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ __('app.field.submit') }}
            </button>
            <button type="button" class="btn btn-outline-secondary btn-lg w-100 mt-2 d-none" data-step-back>{{ __('app.field.change') }}</button>
        </div>
    </form>
@endsection
