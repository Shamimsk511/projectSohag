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

                {{-- Collection: officer and installment plan, saved with the loan in one step. --}}
                @php
                    $frequency = old('frequency', \App\Enums\PlanFrequency::Daily->value);
                    $weekdays = array_map('intval', (array) old('weekdays', \App\Models\CollectionPlan::DEFAULT_WEEKDAYS));
                    $today = today(config('app.display_timezone'));
                    $currentOfficer = $officers->firstWhere('id', $currentOfficerId);
                @endphp
                <fieldset class="col-12 border-top pt-3" data-collection-fields>
                    <legend class="fs-5 fw-semibold">{{ __('app.accounts.collection_group') }}</legend>
                    <p class="small text-body-secondary">{{ __('app.accounts.collection_hint') }}</p>
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="field_officer_id" class="form-label">{{ __('app.parties.field_officer') }}</label>
                            @if ($canAssign)
                                <select id="field_officer_id" name="field_officer_id" @class(['form-select', 'is-invalid' => $errors->has('field_officer_id')])>
                                    <option value="">{{ $currentOfficer ? __('app.accounts.officer_keep') : __('app.accounts.officer_none') }}</option>
                                    @foreach ($officers as $officer)
                                        <option value="{{ $officer->id }}" @selected((int) old('field_officer_id', $currentOfficerId) === $officer->id)>{{ $officer->name }}</option>
                                    @endforeach
                                </select>
                                @error('field_officer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @else
                                <div class="form-control-plaintext">{{ $currentOfficer?->name ?? __('app.schedules.unassigned') }}</div>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label for="frequency" class="form-label">{{ __('app.schedules.frequency') }}</label>
                            <select id="frequency" name="frequency" @class(['form-select', 'is-invalid' => $errors->has('frequency')])>
                                @foreach (\App\Enums\PlanFrequency::cases() as $case)
                                    <option value="{{ $case->value }}" @selected($frequency === $case->value)>{{ __('app.schedules.frequencies.'.$case->value) }}</option>
                                @endforeach
                            </select>
                            @error('frequency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <x-form.input name="starts_on" type="date" :label="__('app.accounts.first_collection')"
                                          :value="$today->copy()->addDay()->toDateString()" :min="$today->toDateString()" />
                        </div>
                        <fieldset class="col-12" data-daily-only>
                            <legend class="form-label fs-6">{{ __('app.schedules.weekdays') }}</legend>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach ([6, 0, 1, 2, 3, 4, 5] as $day)
                                    <input class="btn-check" type="checkbox" name="weekdays[]" value="{{ $day }}" id="weekday-{{ $day }}" autocomplete="off"
                                           @checked(in_array($day, $weekdays, true))>
                                    <label class="btn btn-outline-primary" for="weekday-{{ $day }}">{{ __('app.schedules.weekday_names')[$day] }}</label>
                                @endforeach
                            </div>
                            @error('weekdays')<div class="text-danger mt-1">{{ $message }}</div>@enderror
                        </fieldset>
                        <div class="col-md-6">
                            <x-form.input name="installment_amount" :label="__('app.schedules.installment_amount').' (৳)'" inputmode="decimal" autocomplete="off" />
                        </div>
                        <div class="col-md-6">
                            <x-form.input name="installment_count" type="number" :label="__('app.accounts.installment_count')" min="1" max="1000" step="1"
                                          inputmode="numeric" autocomplete="off" />
                        </div>
                        <input type="hidden" name="installment_basis" value="{{ old('installment_basis') }}">
                        <div class="col-12 form-text mt-1">{{ __('app.accounts.installment_hint') }}</div>
                        <div class="col-12">
                            <div class="alert alert-info mb-0" data-loan-preview data-url="{{ route('admin.loans.preview') }}" hidden>
                                <div class="fw-semibold mb-1">{{ __('app.accounts.preview.title') }}</div>
                                <div>{{ __('app.accounts.contract_amount') }}: <strong data-preview="contract"></strong></div>
                                <div data-preview-carried>{{ __('app.accounts.preview.to_collect') }}: <strong data-preview="to_collect"></strong></div>
                                <div>{{ __('app.accounts.preview.installments') }}: <strong data-preview="count_label"></strong> × <strong data-preview="installment"></strong></div>
                                <div>{{ __('app.accounts.preview.last_installment') }}: <strong data-preview="last_installment"></strong></div>
                                <div>{{ __('app.accounts.preview.first_date') }}: <strong data-preview="first_date"></strong></div>
                                <div>{{ __('app.accounts.preview.last_date') }}: <strong data-preview="last_date"></strong></div>
                                <div class="small text-body-secondary mt-1">{{ __('app.accounts.preview.hint') }}</div>
                            </div>
                        </div>
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
