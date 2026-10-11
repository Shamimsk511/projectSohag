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
    <main class="container py-3">
        @yield('content')
    </main>
</body>
</html>
