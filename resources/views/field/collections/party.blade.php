@extends('layouts.field')

@section('title', __('app.field.collect_all_title'))

@section('content')
    @include('field._member_header')

    <p class="small text-body-secondary">{{ __('app.field.collect_all_hint') }}</p>

    {{-- .js-queue-collect-many: field.js queues one item per filled row and syncs (docs/PWA.md); plain POST without JS. --}}
    <div class="alert alert-danger d-none" role="alert" data-queue-error></div>
    @error('items')<div class="alert alert-danger">{{ $message }}</div>@enderror
    <form method="POST" action="{{ route('field.parties.collections.store', $party) }}" class="js-confirm js-queue-collect-many"
          data-confirm="{{ __('app.field.confirm_many') }}" data-party-name="{{ $party->name }}"
          data-nothing="{{ __('app.field.nothing_to_collect') }}">
        @csrf

        @foreach ($rows as $index => $row)
            @php($schedule = $row['schedule'])
            <fieldset class="border rounded p-2 mb-3" data-collect-row
                      data-schedule-id="{{ $schedule->id }}" data-account-id="{{ $schedule->account_id }}"
                      data-account-number="{{ $schedule->account->account_number }}">
                <legend class="float-none w-auto px-1 fs-6 fw-semibold mb-1">
                    {{ __('app.accounts.types.'.$schedule->account->account_type->value) }} · {{ $schedule->account->account_number }}
                </legend>
                <dl class="row mb-2">
                    <dt class="col-6 fw-normal field-amount-label align-self-center">{{ __('app.schedules.due_date') }}</dt>
                    <dd class="col-6 text-end mb-1 align-self-center">{{ \App\Support\Numerals::localize($schedule->due_date->format('d/m/Y')) }}</dd>
                    <dt class="col-6 fw-normal field-amount-label align-self-center">{{ __('app.field.expected') }}</dt>
                    <dd class="col-6 text-end field-amount mb-1">{{ $schedule->expected_amount->format() }}</dd>
                    <dt class="col-6 fw-normal field-amount-label align-self-center">{{ __('app.field.full_due') }}</dt>
                    <dd class="col-6 text-end field-amount mb-1">{{ $row['remaining']->format() }}</dd>
                    <dt class="col-6 fw-normal field-amount-label align-self-center">{{ $schedule->account->isLoan() ? __('app.accounts.outstanding') : ($schedule->account->isFdr() ? __('app.accounts.remaining_payback') : __('app.accounts.balance')) }}</dt>
                    <dd class="col-6 text-end field-amount mb-1">{{ $row['accountRemaining']->format() }}</dd>
                </dl>
                <input type="hidden" name="items[{{ $index }}][schedule_id]" value="{{ $schedule->id }}">
                <input type="hidden" name="items[{{ $index }}][client_uuid]" value="{{ old("items.$index.client_uuid", $row['clientUuid']) }}">
                <label for="amount-{{ $index }}" class="form-label">{{ __('app.transactions.amount') }}</label>
                <input type="text" id="amount-{{ $index }}" name="items[{{ $index }}][amount]" inputmode="decimal" autocomplete="off"
                       class="form-control form-control-lg fs-3"
                       value="{{ old("items.$index.amount", $row['remaining']->isPositive() ? $row['remaining']->toTaka() : '') }}">
            </fieldset>
        @endforeach

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

        <button type="submit" class="btn btn-success btn-lg w-100">
            <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ __('app.field.submit') }}
        </button>
    </form>
@endsection
