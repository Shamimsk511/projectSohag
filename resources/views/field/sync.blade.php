@extends('layouts.field')

@section('title', __('app.queue.title'))

@section('content')
    <a href="{{ route('field.home') }}" class="btn btn-link px-0 mb-2">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> {{ __('app.field.back') }}
    </a>
    <h1 class="h4 mb-3">{{ __('app.queue.title') }}</h1>

    @include('field._queue_status', ['syncButton' => true])

    {{-- Filled by field.js (sync-page.js) from this phone's queue; textContent only. --}}
    <div data-sync-page data-timezone="{{ config('app.display_timezone') }}">
        @foreach (['rejected', 'pending', 'synced'] as $state)
            <section class="mb-4 d-none" data-queue-section="{{ $state }}">
                <h2 class="h5 mb-2">{{ __('app.queue.'.$state.'_title') }}</h2>
                <div data-queue-items></div>
            </section>
        @endforeach
        <p class="text-body-secondary d-none" data-queue-empty>{{ __('app.queue.empty') }}</p>
    </div>

    <template id="queue-item">
        <div class="card mb-2">
            <div class="card-body">
                <div class="d-flex justify-content-between gap-2">
                    <div>
                        <div class="fw-semibold" data-field="party_name"></div>
                        <div class="small text-body-secondary" data-field="account_number"></div>
                    </div>
                    <div class="fw-semibold text-nowrap" data-field="amount_label"></div>
                </div>
                <div class="small text-body-secondary mt-1">{{ __('app.queue.collected_at') }}: <span data-field="collected_at_label"></span></div>
                <div class="small text-body-secondary d-none" data-pending-info>
                    <span data-field="tries"></span> · {{ __('app.queue.last_try') }}: <span data-field="updated_at_label"></span>
                </div>

                <div class="alert alert-danger small mt-2 mb-0 d-none" data-rejection>
                    <strong>{{ __('app.queue.reason') }}:</strong> <span data-field="reason"></span>
                    <ul class="mb-0 ps-3" data-errors></ul>
                    <div class="mt-1 d-none" data-office-only>{{ __('app.queue.office_only') }}</div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-2 d-none" data-rejected-actions>
                    <button type="button" class="btn btn-primary btn-lg js-queue-fix">
                        <i class="bi bi-pencil" aria-hidden="true"></i> {{ __('app.queue.fix') }}
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-lg js-queue-discard" data-confirm="{{ __('app.queue.discard_confirm') }}">
                        <i class="bi bi-trash" aria-hidden="true"></i> {{ __('app.queue.discard') }}
                    </button>
                </div>

                <form class="mt-3 d-none js-queue-fix-form" novalidate>
                    <div class="mb-2">
                        <label class="form-label">{{ __('app.transactions.amount') }}
                            <input type="text" name="amount" inputmode="decimal" autocomplete="off" required class="form-control form-control-lg fs-4">
                        </label>
                        <div class="invalid-feedback">{{ __('app.queue.invalid_amount') }}</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label w-100">{{ __('app.transactions.payment_method') }}
                            <select name="payment_method" class="form-select">
                                @foreach (\App\Enums\PaymentMethod::cases() as $method)
                                    <option value="{{ $method->value }}">{{ __('app.transactions.payment_methods.'.$method->value) }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="mb-2">
                        <label class="form-label w-100">{{ __('app.transactions.description') }}
                            <textarea name="description" rows="2" maxlength="500" class="form-control"></textarea>
                        </label>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success btn-lg flex-grow-1">{{ __('app.queue.resend') }}</button>
                        <button type="button" class="btn btn-outline-secondary btn-lg js-queue-fix-cancel">{{ __('app.queue.cancel') }}</button>
                    </div>
                </form>

                <a class="btn btn-outline-secondary btn-lg mt-2 d-none" data-receipt>
                    <i class="bi bi-receipt" aria-hidden="true"></i> {{ __('app.queue.receipt') }} <span data-field="receipt_no"></span>
                </a>
            </div>
        </div>
    </template>
@endsection
