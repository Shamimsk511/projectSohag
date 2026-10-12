{{-- One collapsible action section on the account page. Expects $key, $icon, $tone, $title, $body (view name) and $openSection. --}}
@php($isOpen = $openSection === $key)
<div class="accordion-item account-section account-section--{{ $tone }}" id="section-{{ $key }}">
    <h3 class="accordion-header">
        <button type="button" @class(['accordion-button', 'collapsed' => ! $isOpen]) data-bs-toggle="collapse"
                data-bs-target="#section-{{ $key }}-body" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-controls="section-{{ $key }}-body">
            <span class="account-section__icon text-{{ $tone }}"><i class="bi {{ $icon }}" aria-hidden="true"></i></span>
            {{ $title }}
        </button>
    </h3>
    <div id="section-{{ $key }}-body" @class(['accordion-collapse collapse', 'show' => $isOpen]) data-bs-parent="#account-sections">
        <div class="accordion-body">
            @include($body)
        </div>
    </div>
</div>
