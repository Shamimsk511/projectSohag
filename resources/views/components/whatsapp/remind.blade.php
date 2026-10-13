{{-- Reminder for one schedule row. Needs the row's party + account loaded. --}}
@props(['schedule', 'remaining', 'badge' => null])
@inject('whatsapp', 'App\Services\WhatsAppService')
@php($href = $remaining->isPositive() ? \App\Support\WhatsApp::link($schedule->party->phone, $whatsapp->reminderText($schedule, $remaining)) : null)
@if ($href)
    @can('remind', $schedule)
        <x-whatsapp.button :href="$href" :log="['kind' => 'reminder', 'schedule_id' => $schedule->id]" :label="__('app.whatsapp.remind')" :badge="$badge" {{ $attributes }} />
    @endcan
@endif
