@extends('layouts.field')

@section('title', __('app.field.offline_title'))

{{-- Cached by the service worker and shown for any user, so no logout form (it would carry a stale CSRF token). --}}
@section('hide_logout', true)

@section('content')
    {{-- Filled by field.js from the list saved on the officer's last online visit (textContent only). Collections made here go to the offline queue. --}}
    <div class="d-none" data-offline-today data-timezone="{{ config('app.display_timezone') }}">
        <div class="alert alert-warning" role="status">
            <i class="bi bi-wifi-off" aria-hidden="true"></i>
            <strong>{{ __('app.field.offline_title') }}</strong><br>
            {{ __('app.field.offline_saved_at') }} <span data-field="generated_label"></span>
        </div>
        <div class="alert alert-danger d-none" role="status" data-stale>{{ __('app.field.offline_stale') }}</div>
        <div class="alert alert-warning d-none" role="status" data-closed>{{ __('app.closings.today_closed') }}</div>
        @include('field._queue_status')

        <h1 class="h4 mb-3">{{ __('app.today') }}</h1>
        <div class="card mb-3">
            <div class="card-body">
                <div class="text-body-secondary small mb-2" data-field="visits"></div>
                <div class="row text-center g-2">
                    <div class="col-4">
                        <div class="small text-body-secondary">{{ __('app.field.expected') }}</div>
                        <div class="fw-semibold" data-field="expected"></div>
                    </div>
                    <div class="col-4">
                        <div class="small text-body-secondary">{{ __('app.field.collected') }}</div>
                        <div class="fw-semibold text-success" data-field="collected"></div>
                    </div>
                    <div class="col-4">
                        <div class="small text-body-secondary">{{ __('app.field.remaining') }}</div>
                        <div class="fw-semibold text-danger" data-field="remaining"></div>
                    </div>
                </div>
                <div class="d-flex justify-content-between border-top mt-3 pt-2">
                    <span class="text-body-secondary">{{ __('app.cash.in_hand') }}</span>
                    <span class="fw-semibold" data-field="cash_in_hand"></span>
                </div>
            </div>
        </div>
        <div data-rows="today"></div>
        <p class="text-body-secondary d-none" data-empty>{{ __('app.field.empty') }}</p>
        <h2 class="h5 mt-4 mb-2 d-none" data-earlier-title>{{ __('app.field.earlier') }}</h2>
        <div data-rows="earlier"></div>
        <a href="{{ route('field.home') }}" class="btn btn-primary btn-lg w-100 mt-3">
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            {{ __('app.field.retry') }}
        </a>
    </div>

    <template id="offline-row">
        <div class="card mb-2">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold" data-field="party_name"></div>
                        <div class="small text-body-secondary" data-field="details"></div>
                        <div class="small text-body-secondary d-none" data-due>{{ __('app.schedules.due_date') }}: <span data-field="due_date_label"></span></div>
                    </div>
                    <span class="badge" data-field="status_label"></span>
                </div>
                <div class="small mt-2">
                    {{ __('app.field.expected') }}: <span class="fw-semibold" data-field="expected"></span><br>
                    {{ __('app.field.collected') }}: <span class="fw-semibold" data-field="collected"></span><br>
                    {{ __('app.field.remaining') }}: <span class="fw-semibold" data-field="remaining"></span>
                </div>
                <div class="small fw-semibold text-warning-emphasis d-none" data-unsynced></div>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <button type="button" class="btn btn-primary btn-lg d-none js-offline-collect">
                        <i class="bi bi-cash-coin" aria-hidden="true"></i> {{ __('app.field.collect') }}
                    </button>
                    <a class="btn btn-outline-secondary btn-lg d-none" data-phone>
                        <i class="bi bi-telephone" aria-hidden="true"></i> <span data-field="party_phone"></span>
                    </a>
                </div>
            </div>
        </div>
    </template>

    {{-- Offline collect form for one snapshot row; saved to the queue with the snapshot's user id. --}}
    <div class="d-none" data-offline-collect>
        <button type="button" class="btn btn-link px-0 mb-2 js-offline-back">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> {{ __('app.queue.back_to_list') }}
        </button>
        <div class="alert alert-warning" role="status">{{ __('app.queue.saved_offline') }}</div>
        <h1 class="h4" data-field="party_name"></h1>
        <p class="text-body-secondary mb-3" data-field="details"></p>
        <dl class="row bg-body border rounded py-2 mx-0 mb-3">
            <dt class="col-7 fw-normal text-body-secondary">{{ __('app.field.expected') }}</dt>
            <dd class="col-5 text-end fw-semibold" data-field="expected"></dd>
            <dt class="col-7 fw-normal text-body-secondary">{{ __('app.field.collected_so_far') }}</dt>
            <dd class="col-5 text-end fw-semibold" data-field="collected"></dd>
            <dt class="col-7 fw-normal text-body-secondary">{{ __('app.field.remaining') }}</dt>
            <dd class="col-5 text-end fw-semibold mb-0" data-field="remaining"></dd>
        </dl>
        <form class="js-confirm js-offline-collect-form" data-confirm="{{ __('app.field.confirm') }}" novalidate>
            <div class="mb-3">
                <label for="offline-amount" class="form-label">{{ __('app.transactions.amount') }}</label>
                <input type="text" id="offline-amount" name="amount" inputmode="decimal" autocomplete="off" required class="form-control form-control-lg fs-3">
                <div class="invalid-feedback">{{ __('app.queue.invalid_amount') }}</div>
                <button type="button" class="btn btn-outline-primary btn-lg w-100 mt-2 js-full-due" data-target="offline-amount">
                    {{ __('app.field.full_due') }}: <span data-field="remaining"></span>
                </button>
            </div>
            <div class="mb-3">
                <label for="offline-payment-method" class="form-label">{{ __('app.transactions.payment_method') }}</label>
                <select id="offline-payment-method" name="payment_method" class="form-select">
                    @foreach (\App\Enums\PaymentMethod::cases() as $method)
                        <option value="{{ $method->value }}" @selected($method === \App\Enums\PaymentMethod::Cash)>{{ __('app.transactions.payment_methods.'.$method->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label for="offline-description" class="form-label">{{ __('app.transactions.description') }}</label>
                <textarea id="offline-description" name="description" rows="2" maxlength="500" class="form-control"></textarea>
            </div>
            <button type="submit" class="btn btn-success btn-lg w-100">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ __('app.field.submit') }}
            </button>
        </form>
    </div>

    {{-- Provisional receipt for a collection saved offline: no receipt number until the server accepts it. --}}
    <div class="d-none" data-offline-receipt>
        <div class="card">
            <div class="card-body">
                <h1 class="h4">{{ __('app.queue.provisional_title') }}</h1>
                <div class="alert alert-warning" role="status">{{ __('app.queue.provisional_note') }}</div>
                <dl class="row mb-0">
                    <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.member') }}</dt>
                    <dd class="col-7" data-field="party_name"></dd>
                    <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.account') }}</dt>
                    <dd class="col-7" data-field="account_number"></dd>
                    <dt class="col-5 fw-normal text-body-secondary">{{ __('app.receipts.amount') }}</dt>
                    <dd class="col-7 fw-semibold" data-field="amount_label"></dd>
                    <dt class="col-5 fw-normal text-body-secondary">{{ __('app.queue.saved_at') }}</dt>
                    <dd class="col-7" data-field="saved_at_label"></dd>
                </dl>
            </div>
        </div>
        <button type="button" class="btn btn-primary btn-lg w-100 mt-3 js-offline-back">{{ __('app.queue.back_to_list') }}</button>
    </div>

    <div class="text-center py-5" data-offline-generic>
        <i class="bi bi-wifi-off display-1 text-secondary" aria-hidden="true"></i>
        <h1 class="h3 mt-3">{{ __('app.field.offline_title') }}</h1>
        <p class="text-secondary">{{ __('app.field.offline_text') }}</p>
        <a href="{{ route('field.home') }}" class="btn btn-primary btn-lg">
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            {{ __('app.field.retry') }}
        </a>
    </div>
@endsection
