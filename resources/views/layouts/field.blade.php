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
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <title>@hasSection('title')@yield('title') | @endif{{ config('app.name') }}</title>
    @vite('resources/js/field.js')
</head>
{{-- Service worker only for built assets: the manifest hash versions its cache; never under the Vite dev server. --}}
{{-- data-user-id: live signed-in page, so field.js may sync the offline queue (never on the cached, sessionless pages). --}}
<body class="bg-body-tertiary" @if (! Vite::isRunningHot() && $swVersion = Vite::manifestHash()) data-sw="/field-sw.js?v={{ $swVersion }}" @endif
    @auth @sectionMissing('hide_logout') data-user-id="{{ auth()->id() }}" @endif @endauth>
    <header class="navbar bg-body border-bottom px-3 d-print-none">
        <span class="navbar-brand mb-0 h1">{{ config('app.name') }}</span>
        @sectionMissing('hide_logout')
            {{-- .js-logout: field.js clears the offline list before signing out. --}}
            <form method="POST" action="{{ route('logout') }}" class="js-logout">
                @csrf
                <button type="submit" class="btn btn-outline-secondary btn-lg">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    {{ __('app.logout') }}
                </button>
            </form>
        @endif
    </header>
    <main class="container py-3">
        @yield('content')
    </main>
    <script type="application/json" id="field-strings">@json(__('app.queue'))</script>
</body>
</html>
