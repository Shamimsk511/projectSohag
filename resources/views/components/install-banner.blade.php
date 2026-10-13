{{-- Install prompt (resources/js/install-banner.js): hidden until the browser offers install (Android) or on iPhone Safari; never when already installed. --}}
<div class="alert alert-info d-flex align-items-center gap-2 m-3 mb-0 d-print-none d-none" role="region" aria-label="{{ __('app.install.title') }}" data-install-banner>
    <i class="bi bi-phone fs-3" aria-hidden="true"></i>
    <div class="flex-grow-1">
        <div class="fw-semibold">{{ __('app.install.title') }}</div>
        <div class="small d-none" data-install-ios>{{ __('app.install.ios') }}</div>
    </div>
    <button type="button" class="btn btn-primary text-nowrap d-none" data-install-button>{{ __('app.install.button') }}</button>
    <button type="button" class="btn-close" aria-label="{{ __('app.install.close') }}" data-install-close></button>
</div>
