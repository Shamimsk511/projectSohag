<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#198754">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ __('app.portal.title') }}">
    <link rel="manifest" href="/portal.webmanifest">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <title>@hasSection('title')@yield('title') | @endif{{ config('app.name') }}</title>
    @vite('resources/js/portal.js')
</head>
<body class="bg-body-tertiary" @if (! Vite::isRunningHot() && $swVersion = Vite::manifestHash()) data-sw="/portal-sw.js?v={{ $swVersion }}" @endif>
    <header class="navbar bg-body border-bottom px-3 d-print-none">
        <span class="navbar-brand mb-0 h1 d-flex align-items-center gap-2"><img src="/icons/logo-mark.png" width="32" height="32" alt="">{{ config('app.name') }}</span>
        @if (\App\Support\PortalSession::partyId())
            <form method="POST" action="{{ route('portal.lock') }}">
                @csrf
                <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-lock" aria-hidden="true"></i> {{ __('app.portal.lock') }}</button>
            </form>
        @endif
    </header>
    <x-install-banner />
    <main class="container py-3" style="max-width: 40rem">
        @yield('content')
    </main>
</body>
</html>
