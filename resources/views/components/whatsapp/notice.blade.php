{{-- Notice to the member about a loan given or a saving withdrawal. $account passed in (no lazy loading in lists). --}}
@props(['transaction', 'account'])
@inject('whatsapp', 'App\Services\WhatsAppService')
@php($text = $whatsapp->noticeText($transaction, $account))
@php($href = $text ? \App\Support\WhatsApp::link($account->party->phone, $text) : null)
@if ($href)
    @can('shareNotice', $transaction)
        <x-whatsapp.button :href="$href" :log="['kind' => 'notice', 'transaction_id' => $transaction->id]" :label="__('app.whatsapp.send')" {{ $attributes }} />
    @endcan
@endif
