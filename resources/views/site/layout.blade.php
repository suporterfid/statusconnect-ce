{{-- Public site layout (#122): landing page and legal pages. Same-origin assets only: no third-party scripts, fonts or stylesheets. --}}
@php
    $htmlLang = $locale === 'en' ? 'en' : 'pt-BR';
    $home = $locale === 'en' ? url('/en') : url('/');
@endphp
<!doctype html>
<html lang="{{ $htmlLang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <meta name="description" content="{{ __('landing.meta_description') }}">
    <meta property="og:title" content="@yield('title')">
    <meta property="og:description" content="{{ __('landing.meta_description') }}">
    <meta property="og:image" content="{{ asset('brand/icon-512.png') }}">
@include('site.partials.head')
    @yield('head')
    <style>
        @yield('styles')
    </style>
</head>
<body>
    <a href="#main-content" class="skip-link">{{ __('landing.skip_link') }}</a>
    <header class="site-header sign">
        <div class="wrap">
            <a class="brand" href="{{ $home }}" aria-label="StatusConnect">@include('site.partials.mark')<span class="brand-word">Status<span>Connect</span></span></a>
            <nav class="site-nav" aria-label="{{ __('landing.nav_label') }}">
                <ul>
                    {{-- Sections of the commercial landing page; the default home page (#250) has none. --}}
                    @unless ($plainNav ?? false)
                    <li class="nav-section"><a href="{{ $home }}#como-funciona">{{ __('landing.nav.how') }}</a></li>
                    <li class="nav-section"><a href="{{ $home }}#recursos">{{ __('landing.nav.features') }}</a></li>
                    <li class="nav-section"><a href="{{ $home }}#planos">{{ __('landing.nav.pricing') }}</a></li>
                    <li class="nav-section"><a href="{{ $home }}#faq">{{ __('landing.nav.faq') }}</a></li>
                    @endunless
                    @hasSection('language_switch')
                        <li>@yield('language_switch')</li>
                    @endif
                    <li><a class="nav-login" href="{{ url('/app/login') }}">{{ __('landing.sign_in') }}</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main id="main-content">
        @yield('content')
    </main>

    <footer class="site-footer sign">
        <div class="wrap">
            <div>
                <a class="brand" href="{{ $home }}" aria-label="StatusConnect">@include('site.partials.mark')<span class="brand-word">Status<span>Connect</span></span></a>
                <p>© {{ date('Y') }} {{ config('legal.company_name') ?: 'StatusConnect' }}. {{ __('landing.footer_made') }}</p>
            </div>
            <nav aria-label="{{ __('landing.footer_label') }}">
                <ul>
                    <li><a href="{{ url($locale === 'en' ? '/terms' : '/termos') }}">{{ __('landing.terms') }}</a></li>
                    <li><a href="{{ url($locale === 'en' ? '/privacy' : '/privacidade') }}">{{ __('landing.privacy') }}</a></li>
                    <li><a href="{{ url('/api') }}">API</a></li>
                </ul>
            </nav>
        </div>
    </footer>
</body>
</html>
