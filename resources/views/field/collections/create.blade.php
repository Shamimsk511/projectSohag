@extends('layouts.field')

@section('title', __('app.field.collect_title'))

@section('content')
    <a href="{{ route('field.home') }}" class="btn btn-link px-0 mb-2">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> {{ __('app.field.back') }}
    </a>

    <h1 class="h4">{{ $schedule->party->name }}</h1>
    <p class="text-body-secondary mb-3">
        {{ $schedule->party->party_code }} · {{ $schedule->account->account_number }}<br>
        <a href="tel:{{ $schedule->party->phone }}" class="btn btn-outline-secondary btn-lg mt-2">
            <i class="bi bi-telephone" aria-hidden="true"></i> {{ \App\Support\Numerals::localize($schedule->party->phone) }}
        </a>
    </p>

    <dl class="row bg-body border rounded py-2 mx-0 mb-3">
        <dt class="col-7 fw-normal text-body-secondary">{{ __('app.field.expected') }}</dt>
        <dd class="col-5 text-end fw-semibold">{{ $schedule->expected_amount->format() }}</dd>
        <dt class="col-7 fw-normal text-body-secondary">{{ __('app.field.collected_so_far') }}</dt>
        <dd class="col-5 text-end fw-semibold">{{ $collected->format() }}</dd>
        <dt class="col-7 fw-normal text-body-secondary">{{ __($schedule->account->isLoan() ? 'app.accounts.outstanding' : 'app.accounts.balance') }}</dt>
        <dd class="col-5 text-end fw-semibold mb-0">{{ $accountRemaining->format() }}</dd>
    </dl>

    <form method="POST" action="{{ route('field.collections.store', $schedule) }}" class="js-confirm" data-confirm="{{ __('app.field.confirm') }}">
        @csrf
        <input type="hidden" name="client_uuid" value="{{ old('client_uuid', $clientUuid) }}">

        <div class="mb-3">
            <x-form.input name="amount" :label="__('app.transactions.amount')" inputmode="decimal" autocomplete="off" required
                class="form-control-lg fs-3" data-full-due="{{ $remaining->toTaka() }}" />
            @if ($remaining->isPositive())
                <button type="button" class="btn btn-outline-primary btn-lg w-100 mt-2 js-full-due" data-target="amount">
                    {{ __('app.field.full_due') }}: {{ $remaining->format() }}
                </button>
            @endif
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

        <button type="submit" class="btn btn-success btn-lg w-100">
            <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ __('app.field.submit') }}
        </button>
    </form>
@endsection
