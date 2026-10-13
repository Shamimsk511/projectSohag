{{-- WhatsApp click-to-chat link; resources/js/whatsapp.js logs the tap (best effort) and fills the badge. --}}
@props(['href', 'log', 'label', 'badge' => null])
@php($logUrl = request()->routeIs('field.*') ? route('field.whatsapp.log') : route('admin.whatsapp.log'))
<span class="js-whatsapp-wrap d-block">
    <a href="{{ $href }}" target="_blank" rel="noopener" {{ $attributes->merge(['class' => 'btn btn-outline-success js-whatsapp']) }}
       data-log-url="{{ $logUrl }}" data-csrf="{{ csrf_token() }}" data-log="{{ json_encode($log) }}">
        <i class="bi bi-whatsapp" aria-hidden="true"></i> {{ $label }}
    </a>
    <span @class(['small text-success d-block', 'd-none' => $badge === null]) data-whatsapp-badge>{{ $badge }}</span>
</span>
