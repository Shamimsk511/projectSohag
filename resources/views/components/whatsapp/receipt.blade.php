{{-- Receipt to the member. Needs the transaction's party + account loaded. --}}
@props(['transaction'])
@inject('whatsapp', 'App\Services\WhatsAppService')
@php($href = $transaction->receipt_no && ! $transaction->type->isOffice() && ! $transaction->reversal ? \App\Support\WhatsApp::link($transaction->party?->phone, $whatsapp->receiptText($transaction)) : null)
@if ($href)
    @can('viewReceipt', $transaction)
        <x-whatsapp.button :href="$href" :log="['kind' => 'receipt', 'transaction_id' => $transaction->id]" :label="__('app.whatsapp.thank')" {{ $attributes }} />
    @endcan
@endif
