<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1e4d3b">
    <meta name="description" content="{{ __('app.home.meta') }}">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <title>@hasSection('title')@yield('title') | @endif{{ __('app.site.org_name') }}</title>
    @vite('resources/js/public.js')
</head>
<body class="site @yield('body_class')">
    <a class="visually-hidden-focusable site-skip" href="#main">{{ __('app.site.skip') }}</a>
    @yield('body')
</body>
</html>
