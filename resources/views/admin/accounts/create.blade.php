@extends('layouts.admin')

@section('subtitle', __('app.accounts.open'))
@section('content_header_title', __('app.accounts.open'))
@section('content_header_subtitle', $party->name.' · '.$party->party_code)

@section('content_body')
    <form method="POST" action="{{ route('admin.parties.accounts.store', $party) }}" class="card js-account-form" style="max-width: 40rem">
        @csrf
        <div class="card-body">
            @error('account_type')
                <div class="alert alert-danger py-2">{{ $message }}</div>
            @enderror

            <fieldset class="mb-3">
                <legend class="form-label fs-6">{{ __('app.accounts.type') }}</legend>
                <div class="d-flex gap-2">
                    @foreach ($types as $type)
                        <input type="radio" class="btn-check" name="account_type" id="type-{{ $type->value }}" value="{{ $type->value }}"
                               autocomplete="off" required @checked(old('account_type', 'saving') === $type->value)>
                        <label class="btn btn-outline-primary flex-fill py-3" for="type-{{ $type->value }}">
                            <i @class(['bi', 'bi-piggy-bank' => $type->value === 'saving', 'bi-cash-coin' => $type->value === 'loan', 'bi-bank' => $type->value === 'fdr']) aria-hidden="true"></i>
                            {{ __('app.accounts.types.'.$type->value) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div data-saving-fields data-label-saving="{{ __('app.accounts.agreed_amount') }} (৳)" data-label-fdr="{{ __('app.accounts.agreed_payback') }} (৳)"
                 data-hint-saving="{{ __('app.accounts.agreed_amount_hint') }}" data-hint-fdr="{{ __('app.accounts.agreed_payback_hint') }}">
                <x-form.input name="contract_amount" :label="__('app.accounts.agreed_amount').' (৳)'" inputmode="decimal"
                              autocomplete="off" :hint="__('app.accounts.agreed_amount_hint')" />
            </div>

            <div data-loan-fields class="row g-3">
                <div class="col-md-6">
                    <x-form.input name="principal_amount" :label="__('app.accounts.principal_amount').' (৳)'" inputmode="decimal"
                                  autocomplete="off" :hint="__('app.accounts.principal_amount_hint')" />
                </div>
                <div class="col-md-6">
                    <x-form.input name="extra_amount" :label="__('app.accounts.agreed_extra').' (৳)'" inputmode="decimal"
                                  autocomplete="off" :hint="__('app.accounts.agreed_extra_hint')" />
                </div>
                <div class="col-12">
                    <div class="small text-body-secondary">{{ __('app.accounts.contract_amount') }}: <strong data-contract-total>৳ 0</strong></div>
                </div>
                @if ($canCarryIn)
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="existing_loan" id="existing_loan" value="1" @checked(old('existing_loan'))>
                            <label class="form-check-label" for="existing_loan">{{ __('app.accounts.existing_loan') }}</label>
                        </div>
                        <div class="form-text">{{ __('app.accounts.existing_loan_hint') }}</div>
                    </div>
                    <div class="col-12 row g-3 m-0 p-0" data-carried-fields hidden>
                        <div class="col-md-6">
                            <x-form.input name="received_amount" :label="__('app.accounts.received_before').' (৳)'" inputmode="decimal" autocomplete="off" />
                        </div>
                        <div class="col-md-6">
                            <x-form.input name="carried_as_of" type="date" :label="__('app.accounts.carried_as_of')"
                                          :value="today(config('app.display_timezone'))->toDateString()"
                                          :max="today(config('app.display_timezone'))->toDateString()" />
                        </div>
                    </div>
                @endif
                <fieldset class="col-12" data-new-loan-only>
                    <legend class="form-label fs-6">{{ __('app.transactions.payment_method') }}</legend>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach (\App\Enums\PaymentMethod::cases() as $method)
                            <input type="radio" class="btn-check" name="payment_method" id="method-{{ $method->value }}" value="{{ $method->value }}"
                                   autocomplete="off" @checked(old('payment_method', 'cash') === $method->value)>
                            <label class="btn btn-outline-secondary" for="method-{{ $method->value }}">{{ __('app.transactions.payment_methods.'.$method->value) }}</label>
                        @endforeach
                    </div>
                </fieldset>
            </div>

            <div class="mb-3">
                <x-form.input name="opened_at" type="date" :label="__('app.accounts.opened_at')" required
                              :value="today(config('app.display_timezone'))->toDateString()"
                              :max="today(config('app.display_timezone'))->toDateString()" />
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg" aria-hidden="true"></i> {{ __('app.accounts.open') }}
            </button>
            <a href="{{ route('admin.parties.show', $party) }}" class="btn btn-outline-secondary">{{ __('app.cancel') }}</a>
        </div>
    </form>
@stop

@push('js')
    @vite('resources/js/admin/accounts.js')
@endpush
