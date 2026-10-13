@extends('layouts.public')

@section('title', __('app.home.title'))

@section('body')
    <header class="site-header">
        <div class="container site-header__inner">
            <a class="site-brand" href="{{ route('home') }}">
                <img src="/images/logo-mark.png" width="40" height="40" alt="">
                <span>{{ __('app.site.org_name') }}</span>
            </a>
            <nav class="site-nav d-none d-lg-flex" aria-label="{{ __('app.site.org_name') }}">
                <a href="#services">{{ __('app.site.nav_services') }}</a>
                <a href="#how">{{ __('app.site.nav_how') }}</a>
                <a href="#trust">{{ __('app.site.nav_trust') }}</a>
                <a href="#join">{{ __('app.site.nav_join') }}</a>
            </nav>
            <div class="site-header__actions">
                <x-locale-switch />
                @if ($workspaceRoute)
                    <a class="btn btn-site-outline" href="{{ route($workspaceRoute) }}"><i class="bi bi-grid" aria-hidden="true"></i> <span class="d-none d-sm-inline">{{ __('app.site.open_workspace') }}</span></a>
                @else
                    <a class="btn btn-site-outline" href="{{ route('login') }}"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> <span class="d-none d-sm-inline">{{ __('app.site.staff_login') }}</span></a>
                @endif
            </div>
        </div>
    </header>

    <main id="main">
        <section class="hero">
            <div class="hero__text">
                <p class="hero__tagline">{{ __('app.site.tagline') }}</p>
                <h1 class="hero__title">{{ __('app.home.hero_title') }}</h1>
                <p class="hero__lead">{{ __('app.home.hero_lead') }}</p>
                <div class="hero__cta">
                    <a class="btn btn-jute btn-lg" href="#join">{{ __('app.home.hero_join') }}</a>
                    <a class="btn btn-ghost-light btn-lg" href="#services">{{ __('app.home.hero_more') }}</a>
                </div>
            </div>
            <figure class="hero__photo">
                <img src="/images/home/hero.webp" width="1800" height="1200" alt="{{ __('app.home.hero_caption') }}" fetchpriority="high">
                <div class="receipt-stub" aria-label="{{ __('app.site.nav_trust') }}">
                    <ul>
                        @foreach (__('app.home.facts') as $key => $fact)
                            <li>
                                <i class="bi bi-{{ ['doorstep' => 'house-door', 'receipt' => 'receipt', 'phone' => 'phone'][$key] }}" aria-hidden="true"></i>
                                <div>
                                    <strong>{{ $fact['title'] }}</strong>
                                    <span>{{ $fact['text'] }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </figure>
        </section>

        <section id="services" class="section">
            <div class="container">
                <div class="section__head">
                    <h2>{{ __('app.home.services_title') }}</h2>
                    <p>{{ __('app.home.services_lead') }}</p>
                </div>

                <article class="service">
                    <img class="service__photo" src="/images/home/women-fish.webp" width="615" height="462" alt="{{ __('app.home.loan_photo') }}" loading="lazy">
                    <div class="service__body">
                        <h3><i class="bi bi-cash-coin" aria-hidden="true"></i> {{ __('app.home.loan_title') }}</h3>
                        <p>{{ __('app.home.loan_text') }}</p>
                        <ul class="ticks">
                            @foreach (__('app.home.loan_points') as $point)
                                <li>{{ $point }}</li>
                            @endforeach
                        </ul>
                    </div>
                </article>

                <article class="service service--reverse">
                    <img class="service__photo" src="/images/home/fields.webp" width="1024" height="768" alt="{{ __('app.home.saving_photo') }}" loading="lazy">
                    <div class="service__body">
                        <h3><i class="bi bi-piggy-bank" aria-hidden="true"></i> {{ __('app.home.saving_title') }}</h3>
                        <p>{{ __('app.home.saving_text') }}</p>
                        <ul class="ticks">
                            @foreach (__('app.home.saving_points') as $point)
                                <li>{{ $point }}</li>
                            @endforeach
                        </ul>
                    </div>
                </article>

                <div class="extras">
                    <div class="extra">
                        <i class="bi bi-safe2" aria-hidden="true"></i>
                        <div>
                            <h3>{{ __('app.home.fdr_title') }}</h3>
                            <p>{{ __('app.home.fdr_text') }}</p>
                        </div>
                    </div>
                    <div class="extra">
                        <i class="bi bi-phone" aria-hidden="true"></i>
                        <div>
                            <h3>{{ __('app.home.portal_title') }}</h3>
                            <p>{{ __('app.home.portal_text') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="how" class="section section--paddy">
            <div class="container">
                <div class="section__head">
                    <h2>{{ __('app.home.how_title') }}</h2>
                </div>
                <ol class="steps">
                    @foreach (__('app.home.steps') as $step)
                        <li class="step">
                            <h3>{{ $step['title'] }}</h3>
                            <p>{{ $step['text'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        <section id="trust" class="section">
            <div class="container trust">
                <div class="section__head trust__head">
                    <h2>{{ __('app.home.trust_title') }}</h2>
                    <p>{{ __('app.home.trust_lead') }}</p>
                </div>
                <dl class="trust__list">
                    @foreach (__('app.home.trust') as $item)
                        <div class="trust__item">
                            <dt><i class="bi bi-{{ $item['icon'] }}" aria-hidden="true"></i> {{ $item['title'] }}</dt>
                            <dd>{{ $item['text'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </section>

        <section id="join" class="join">
            <img class="join__photo" src="/images/home/tea-garden.webp" width="1400" height="788" alt="" loading="lazy">
            <div class="container join__inner">
                <div class="join__card">
                    <h2>{{ __('app.home.join_title') }}</h2>
                    <p>{{ __('app.home.join_text') }}</p>
                    <p class="join__bring"><i class="bi bi-person-vcard" aria-hidden="true"></i> {{ __('app.home.join_bring') }}</p>
                    <p class="join__member">{{ __('app.home.join_member') }}</p>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container site-footer__inner">
            <div class="site-brand site-brand--footer">
                <img src="/images/logo-mark.png" width="32" height="32" alt="">
                <span>{{ __('app.site.org_name') }}</span>
            </div>
            <p class="mb-0">{{ __('app.site.copyright', ['year' => \App\Support\Numerals::localize((string) now(config('app.display_timezone'))->year)]) }}</p>
            <p class="mb-0 site-footer__small">{{ __('app.site.photo_note') }} <a href="{{ route('login') }}">{{ __('app.site.staff_login') }}</a></p>
            <p class="mb-0 site-footer__credit">{{ __('app.site.created_by') }} Shamim Reza (<a href="tel:+8801970598449">+8801970-598449</a>)</p>
        </div>
    </footer>
@endsection
