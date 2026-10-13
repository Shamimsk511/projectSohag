{{-- Member portal card on the member page. Expects $party (portalAccess loaded), $canManagePortal. --}}
@php($access = $party->portalAccess)
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title mb-0"><i class="bi bi-phone me-1" aria-hidden="true"></i> {{ __('app.portal.title') }}</h3></div>
    <div class="card-body">
        @error('portal')<div class="alert alert-danger">{{ $message }}</div>@enderror
        @if (session('portal_pin'))
            <div class="alert alert-warning">
                <div class="small">{{ __('app.portal.office_pin') }}</div>
                <div class="display-6 fw-bold font-monospace" data-portal-pin>{{ session('portal_pin') }}</div>
                <div class="small">{{ __('app.portal.pin_once_hint') }}</div>
            </div>
        @endif
        @if (! $access)
            <p class="text-body-secondary">{{ __('app.portal.not_issued') }}</p>
            <form method="POST" action="{{ route('admin.parties.portal.store', $party) }}">
                @csrf
                <button type="submit" class="btn btn-primary"><i class="bi bi-link-45deg" aria-hidden="true"></i> {{ __('app.portal.issue') }}</button>
            </form>
        @else
            <p class="mb-2">
                @if ($access->isLocked())
                    <span class="badge text-bg-danger">{{ __('app.portal.status_locked') }}</span>
                @elseif ($access->must_change_pin)
                    <span class="badge text-bg-warning">{{ __('app.portal.status_waiting') }}</span>
                @else
                    <span class="badge text-bg-success">{{ __('app.portal.status_active') }}</span>
                @endif
                @if ($access->last_login_at)
                    <span class="small text-body-secondary ms-2">{{ __('app.portal.last_login') }}: {{ \App\Support\Numerals::localize($access->last_login_at->copy()->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i')) }}</span>
                @endif
            </p>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary" href="{{ route('admin.parties.portal.card', $party) }}" target="_blank" rel="noopener"><i class="bi bi-qr-code" aria-hidden="true"></i> {{ __('app.portal.print_card') }}</a>
                <x-whatsapp.portal :party="$party" :access="$access" />
                <form method="POST" action="{{ route('admin.parties.portal.reset-pin', $party) }}" class="js-confirm"
                      data-confirm="{{ json_encode(['title' => __('app.assignments.confirm_title'), 'text' => __('app.portal.reset_confirm'), 'confirm' => __('app.portal.reset_pin'), 'cancel' => __('app.cancel')]) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-warning">{{ __('app.portal.reset_pin') }}</button>
                </form>
                <form method="POST" action="{{ route('admin.parties.portal.regenerate', $party) }}" class="js-confirm"
                      data-confirm="{{ json_encode(['title' => __('app.assignments.confirm_title'), 'text' => __('app.portal.regenerate_confirm'), 'confirm' => __('app.portal.regenerate'), 'cancel' => __('app.cancel')]) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger">{{ __('app.portal.regenerate') }}</button>
                </form>
            </div>
        @endif
    </div>
</div>
