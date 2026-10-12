{{-- Offline queue status, filled by field.js (pending/rejected counts, last sync result). Sync button only on live pages. --}}
<div class="alert alert-info d-none" role="status" data-queue-status>
    <div data-queue-message></div>
    <a href="{{ route('login') }}" class="btn btn-outline-primary btn-lg mt-2 d-none" data-queue-sign-in>{{ __('app.queue.sign_in_link') }}</a>
    @if ($detailsLink ?? false)
        <a href="{{ route('field.sync') }}" class="btn btn-outline-secondary btn-lg w-100 mt-2 d-none" data-queue-details>
            <i class="bi bi-list-check" aria-hidden="true"></i> {{ __('app.queue.details') }}
        </a>
    @endif
    @if ($syncButton ?? false)
        <button type="button" class="btn btn-primary btn-lg w-100 mt-2 d-none js-sync-now">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> {{ __('app.queue.sync_now') }}
        </button>
    @endif
</div>
