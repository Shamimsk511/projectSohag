@extends('layouts.public')

@section('title', __('app.login.title'))
@section('body_class', 'site--login')

@section('body')
    <main id="main" class="login">
        <aside class="login__panel">
            <img src="/images/home/tea-garden.webp" width="1400" height="788" alt="">
            <div class="login__panel-text">
                <a class="site-brand site-brand--light" href="{{ route('home') }}">
                    <img src="/images/logo-mark.png" width="40" height="40" alt="">
                    <span>{{ __('app.site.org_name') }}</span>
                </a>
                <div>
                    <h2>{{ __('app.login.panel_title') }}</h2>
                    <p>{{ __('app.login.panel_text') }}</p>
                </div>
            </div>
        </aside>

        <section class="login__form-side">
            <div class="login__top">
                <a class="login__back" href="{{ route('home') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> {{ __('app.login.back_home') }}</a>
                <x-locale-switch />
            </div>

            <div class="login__box">
                <img class="login__logo" src="/images/logo.png" alt="{{ __('app.site.org_name') }}">
                <h1>{{ __('app.login.heading') }}</h1>
                <p class="login__lead">{{ __('app.login.lead') }}</p>

                <form method="POST" action="{{ route('login') }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label class="form-label" for="email">{{ __('app.login.email') }}</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" inputmode="email"
                               class="form-control form-control-lg @error('email') is-invalid @enderror" @error('email') aria-describedby="email-error" @enderror>
                        @error('email')
                            <div id="email-error" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-2">
                        <label class="form-label" for="password">{{ __('app.login.password') }}</label>
                        <input id="password" type="password" name="password" required autocomplete="current-password"
                               class="form-control form-control-lg @error('password') is-invalid @enderror" @error('password') aria-describedby="password-error" @enderror>
                        @error('password')
                            <div id="password-error" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="show-password" data-show-password="password">
                            <label class="form-check-label" for="show-password">{{ __('app.login.show_password') }}</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="remember" id="remember" @checked(old('remember'))>
                            <label class="form-check-label" for="remember">{{ __('app.login.remember') }}</label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-paddy btn-lg w-100">{{ __('app.login.submit') }}</button>
                </form>

                @if (Route::has('password.request'))
                    <p class="text-center mt-3 mb-0"><a href="{{ route('password.request') }}">{{ __('app.login.forgot') }}</a></p>
                @endif

                <p class="login__note"><i class="bi bi-info-circle" aria-hidden="true"></i> {{ __('app.login.members_note') }}</p>
            </div>
        </section>
    </main>
@endsection
