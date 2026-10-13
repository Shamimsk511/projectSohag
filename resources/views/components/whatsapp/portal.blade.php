{{-- Portal link to the member over WhatsApp (office only). --}}
@props(['party', 'access'])
@inject('whatsapp', 'App\Services\WhatsAppService')
@inject('portal', 'App\Services\PortalAccessService')
@can('managePortal', $party)
    @php($href = \App\Support\WhatsApp::link($party->phone, $whatsapp->portalText($party, $portal->link($access))))
    @if ($href)
        <x-whatsapp.button :href="$href" :log="['kind' => 'portal', 'party_id' => $party->id]" :label="__('app.whatsapp.share_portal')" {{ $attributes }} />
    @endif
@endcan
