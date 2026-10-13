<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0d6efd">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ __('app.field.app_name') }}">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <title>@hasSection('title')@yield('title') | @endif{{ __('app.field.app_name') }}</title>
    @php($pinLock = request()->is('field', 'field/*'))
    @php($pinFresh = $pinLock && auth()->check() && ! View::hasSection('hide_logout') && session()->pull('field_pin_fresh_login', false))
    @if ($pinLock)
        {{-- PIN lock (resources/js/field/pin.js): hide the page before it paints; JS removes the class once unlocked. --}}
        <script>document.documentElement.classList.add('pin-pending');</script>
    @endif
    @vite('resources/js/field.js')
</head>
{{-- Service worker only for built assets: the manifest hash versions its cache; never under the Vite dev server. --}}
{{-- data-user-id: live signed-in page, so field.js may sync the offline queue (never on the cached, sessionless pages). --}}
<body class="bg-body-tertiary" @if (! Vite::isRunningHot() && $swVersion = Vite::manifestHash()) data-sw="/field-sw.js?v={{ $swVersion }}" @endif
    @auth @sectionMissing('hide_logout') data-user-id="{{ auth()->id() }}" @endif @endauth
    @if ($pinLock) @auth @sectionMissing('hide_logout') data-pin-user="{{ auth()->id() }}" data-pin-name="{{ auth()->user()->name }}" @if ($pinFresh) data-pin-fresh-login @endif @endif @endauth @endif>
    <header class="navbar bg-body border-bottom px-3 d-print-none field-header">
        <span class="navbar-brand mb-0 h1 d-flex align-items-center gap-2 fw-bold"><img src="/icons/logo-mark.png" width="40" height="40" alt="">{{ __('app.field.app_name') }}</span>
        @sectionMissing('hide_logout')
            {{-- .js-logout: field.js clears the offline list before signing out. --}}
            <form method="POST" action="{{ route('logout') }}" class="js-logout">
                @csrf
                <button type="submit" class="btn btn-light border btn-lg field-icon-btn" aria-label="{{ __('app.logout') }}" title="{{ __('app.logout') }}">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                </button>
            </form>
        @endif
    </header>
    @if ($pinLock)
        <x-install-banner />
    @endif
    <main class="container py-3">
        @yield('content')
    </main>
    <script type="application/json" id="field-strings">@json(__('app.queue'))</script>
    @if ($pinLock)
        <div id="pin-lock" class="pin-lock d-none" role="dialog" aria-modal="true" aria-labelledby="pin-lock-title">
            <div class="pin-lock-inner">
                <i class="bi bi-shield-lock display-5 text-primary" aria-hidden="true"></i>
                <h1 id="pin-lock-title" class="h4 mt-2 mb-1" data-pin-title></h1>
                <div class="text-body-secondary" data-pin-name></div>
                <div class="pin-dots" data-pin-dots aria-live="polite"></div>
                <div class="text-danger mb-2" role="alert" data-pin-message></div>
                <div class="pin-pad" data-pin-pad>
                    @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit)
                        <button type="button" class="btn btn-outline-primary" data-pin-key="{{ $digit }}">{{ \App\Support\Numerals::localize($digit) }}</button>
                    @endforeach
                    <button type="button" class="btn btn-outline-secondary" data-pin-key="back" aria-label="{{ __('app.pin.back') }}">
                        <i class="bi bi-backspace" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-pin-key="0">{{ \App\Support\Numerals::localize('0') }}</button>
                    <button type="button" class="btn btn-primary" data-pin-key="ok">{{ __('app.pin.ok') }}</button>
                </div>
                <button type="button" class="btn btn-link mt-3" data-pin-forgot>{{ __('app.pin.forgot') }}</button>
            </div>
        </div>
        <script type="application/json" id="field-pin-strings">@json(__('app.pin'))</script>
    @endif
</body>
</html>
