@extends('layouts.admin')

@section('subtitle', $account->account_number)
@section('content_header_title', __('app.accounts.title'))
@section('content_header_subtitle', $account->account_number)

@section('content_body')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php
        $date = fn (?\Illuminate\Support\Carbon $value): ?string => $value ? \App\Support\Numerals::localize($value->format('d/m/Y')) : null;
    @endphp

    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center gap-2 flex-wrap">
                    <h2 class="h5 mb-0">{{ $account->account_number }}</h2>
                    <span class="badge text-bg-info">{{ __('app.accounts.types.'.$account->account_type->value) }}</span>
                    <span @class(['badge', 'text-bg-success' => $account->isActive(), 'text-bg-secondary' => ! $account->isActive()])>
                        {{ $account->isActive() ? __('app.accounts.active') : __('app.accounts.closed') }}
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3 text-center">
                        @if ($account->isLoan())
                            <div class="col-sm-4">
                                <div class="border rounded p-2">
                                    <div class="small text-body-secondary">{{ __('app.accounts.contract_amount') }}</div>
                                    <div class="fs-5 fw-semibold">{{ $account->contract_amount?->format() }}</div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="border rounded p-2">
                                    <div class="small text-body-secondary">{{ __('app.accounts.received') }}</div>
                                    <div class="fs-5 fw-semibold text-success">{{ $received?->format() }}</div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="border rounded p-2">
                                    <div class="small text-body-secondary">{{ __('app.accounts.outstanding') }}</div>
                                    <div class="fs-5 fw-semibold text-danger">{{ $remaining->format() }}</div>
                                </div>
                            </div>
                        @else
                            <div class="col-sm-4">
                                <div class="border rounded p-2">
                                    <div class="small text-body-secondary">{{ __('app.accounts.balance') }}</div>
                                    <div class="fs-5 fw-semibold">{{ $remaining->format() }}</div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <dl class="row mb-0">
                        <dt class="col-sm-4">{{ __('app.accounts.party') }}</dt>
                        <dd class="col-sm-8">
                            <a href="{{ route('admin.parties.show', $account->party) }}">{{ $account->party->name }}</a>
                            <span class="text-body-secondary">({{ $account->party->party_code }})</span>
                        </dd>
                        <dt class="col-sm-4">{{ __('app.accounts.opened_at') }}</dt>
                        <dd class="col-sm-8">{{ $date($account->opened_at) }}</dd>
                        @unless ($account->isActive())
                            <dt class="col-sm-4">{{ __('app.accounts.closed_at') }}</dt>
                            <dd class="col-sm-8">{{ $date($account->closed_at) }} · {{ $account->closedBy?->name }}</dd>
                            @if ($account->close_reason)
                                <dt class="col-sm-4">{{ __('app.accounts.close_reason') }}</dt>
                                <dd class="col-sm-8">{{ $account->close_reason }}</dd>
                            @endif
                        @endunless
                    </dl>
                </div>
            </div>

            @if ($account->isLoan())
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title mb-0">{{ __('app.accounts.adjustments') }}</h3></div>
                    <ul class="list-group list-group-flush">
                        @forelse ($account->adjustments as $adjustment)
                            <li class="list-group-item">
                                <div class="fw-semibold">
                                    {{ __('app.accounts.adjustment_line', ['old' => $adjustment->old_amount->format(), 'new' => $adjustment->new_amount->format()]) }}
                                </div>
                                <div>{{ $adjustment->reason }}</div>
                                <small class="text-body-secondary">
                                    {{ \App\Support\Numerals::localize($adjustment->created_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i')) }}
                                    · {{ $adjustment->createdBy->name }}
                                </small>
                            </li>
                        @empty
                            <li class="list-group-item text-body-secondary">{{ __('app.accounts.no_adjustments') }}</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>

        @if ($account->isActive())
            @can('update', $account)
                <div class="col-lg-4">
                    @if ($account->isLoan())
                        <form method="POST" action="{{ route('admin.accounts.adjust', $account) }}" class="card mb-3">
                            @csrf
                            <div class="card-header"><h3 class="card-title mb-0">{{ __('app.accounts.adjust') }}</h3></div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <x-form.input name="contract_amount" :label="__('app.accounts.new_contract_amount').' (৳)'"
                                                  inputmode="decimal" autocomplete="off" required />
                                </div>
                                <label for="adjust-reason" class="form-label">{{ __('app.accounts.reason') }}</label>
                                <textarea id="adjust-reason" name="reason" rows="2" maxlength="500" required
                                          @class(['form-control', 'is-invalid' => $errors->has('reason')])>{{ old('reason') }}</textarea>
                                @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="card-footer">
                                <button type="submit" class="btn btn-primary w-100">{{ __('app.accounts.adjust_submit') }}</button>
                            </div>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('admin.accounts.close', $account) }}" class="card mb-3 js-confirm"
                          data-confirm="{{ json_encode([
                              'title' => __('app.assignments.confirm_title'),
                              'text' => __('app.accounts.close_confirm'),
                              'confirm' => __('app.accounts.confirm_yes'),
                              'cancel' => __('app.cancel'),
                          ]) }}">
                        @csrf
                        <div class="card-header"><h3 class="card-title mb-0">{{ __('app.accounts.close') }}</h3></div>
                        <div class="card-body">
                            <p class="small text-body-secondary">{{ __('app.accounts.close_hint') }}</p>
                            <label for="close_reason" class="form-label">{{ __('app.accounts.reason') }}</label>
                            <textarea id="close_reason" name="close_reason" rows="2" maxlength="500"
                                      @class(['form-control', 'is-invalid' => $errors->has('close_reason')])>{{ old('close_reason') }}</textarea>
                            @error('close_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="card-footer">
                            <button type="submit" class="btn btn-outline-danger w-100">
                                <i class="bi bi-lock" aria-hidden="true"></i> {{ __('app.accounts.close') }}
                            </button>
                        </div>
                    </form>
                </div>

                @push('js')
                    @vite('resources/js/admin/accounts.js')
                @endpush
            @endcan
        @endif
    </div>
@stop
