{{-- Bengali / English toggle; the choice is kept in the session (SetLocale middleware). --}}
<nav class="locale-switch" aria-label="{{ __('app.site.language') }}">
    @foreach (['bn' => 'বাংলা', 'en' => 'English'] as $code => $label)
        <a href="{{ route('locale.switch', $code) }}" lang="{{ $code }}" @if (app()->getLocale() === $code) aria-current="true" @endif>{{ $label }}</a>
    @endforeach
</nav>
