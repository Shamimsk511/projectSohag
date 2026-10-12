<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') | @endif{{ config('app.name') }}</title>
    @vite('resources/js/field.js')
</head>
<body class="bg-body-tertiary">
    <header class="navbar bg-body border-bottom px-3 d-print-none">
        <span class="navbar-brand mb-0 h1">{{ config('app.name') }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-outline-secondary btn-lg">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                {{ __('app.logout') }}
            </button>
        </form>
    </header>
    <main class="container py-3">
        @yield('content')
    </main>
</body>
</html>
