@extends('adminlte::page')

{{-- Extend and customize the browser title --}}

@section('title')
    {{ __('app.site.org_name') }}
    @hasSection('subtitle') | @yield('subtitle') @endif
@stop

{{-- Extend and customize the page content header --}}

@section('content_header')
    @hasSection('content_header_title')
        <h1 class="text-body-secondary">
            @yield('content_header_title')

            @hasSection('content_header_subtitle')
                <small class="text-body">
                    <i class="bi bi-chevron-right text-body-secondary"></i>
                    @yield('content_header_subtitle')
                </small>
            @endif
        </h1>
    @endif
@stop

{{-- Top bar: member search (anyone who can list members) and the Dhaka date and time --}}

@section('content_top_nav_left')
    @can('viewAny', \App\Models\Party::class)
        <li class="nav-item flex-grow-1 ms-md-2 me-2 top-search">
            <form method="GET" action="{{ route('admin.search') }}" role="search" class="position-relative js-global-search"
                  data-empty="{{ __('app.search.no_results') }}" autocomplete="off">
                <label for="global-search" class="visually-hidden">{{ __('app.search.label') }}</label>
                <div class="input-group">
                    <span class="input-group-text bg-body-tertiary border-end-0"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="search" id="global-search" name="q" class="form-control bg-body-tertiary border-start-0"
                           placeholder="{{ __('app.search.placeholder') }}" maxlength="100"
                           role="combobox" aria-expanded="false" aria-controls="global-search-results" aria-autocomplete="list">
                </div>
                <div id="global-search-results" class="dropdown-menu w-100 shadow" role="listbox"></div>
            </form>
        </li>
    @endcan
@stop

@section('content_top_nav_right')
    @php($now = now(config('app.display_timezone')))
    <li class="nav-item d-none d-lg-flex align-items-center me-2 top-clock js-clock" data-timezone="{{ config('app.display_timezone') }}">
        <i class="bi bi-calendar3 fs-5 me-2 text-body-secondary" aria-hidden="true"></i>
        <div class="lh-sm">
            <div class="fw-semibold" data-clock-date>{{ \App\Support\Numerals::localize($now->translatedFormat('j F Y')) }}</div>
            <div class="small text-body-secondary">
                <span data-clock-day>{{ $now->translatedFormat('l') }}</span>,
                <time data-clock-time datetime="{{ $now->toIso8601String() }}">{{ \App\Support\Numerals::localize($now->format('g:i A')) }}</time>
            </div>
        </div>
    </li>
@stop

{{-- Rename section content to content_body --}}

@section('content')
    @yield('content_body')
@stop

{{-- Create a common footer --}}

@section('footer')
    <div class="d-flex flex-wrap justify-content-between gap-2">
        <strong>{{ __('app.site.org_name') }}</strong>
        <span class="text-body-secondary">{{ __('app.site.created_by') }} Shamim Reza (<a href="tel:+8801970598449">+8801970-598449</a>)</span>
    </div>
@stop
