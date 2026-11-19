@php
    $fmt = new \App\Http\Views\StatusPageFormat($snapshot);
    $brand = $snapshot['branding'] ?? [];
    $accent = $brand['accent_color'] ?? null;
    $accentText = $brand['accent_text'] ?? null;
    $logoUrl = empty($brand['logo_url']) ? null : preg_replace('#^/status/[a-z0-9-]+/logo#', $base.'/logo', $brand['logo_url']);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', $snapshot['locale']) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($unlisted)
        <meta name="robots" content="noindex">
    @endif
    <link rel="alternate" type="application/atom+xml" title="{{ $snapshot['title'] }}" href="{{ $base.'/feed' }}">
    <title>@hasSection('title')@yield('title') · @endif{{ $snapshot['title'] }}</title>
    @include('status.partials.head')
    @if ($logoUrl)
        <link rel="icon" href="{{ $logoUrl }}" type="image/png">
    @elseif ($brand['show_powered_by'] ?? true)
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @endif
    <meta name="theme-color" content="{{ ($accent && $accentText) ? $accent : '#252525' }}">
</head>
<body>
    <a href="#main-content" class="skip-link">{{ __('status.skip_link') }}</a>
    <header class="top"@if ($accent && $accentText) style="--header-bg: {{ $accent }}; --header-text: {{ $accentText }}; --header-muted: color-mix(in srgb, {{ $accentText }} 78%, {{ $accent }});"@endif>
        <div class="wrap">
            <h1>
                <a href="{{ ($base ?: '/') }}">
                    @if ($logoUrl)
                        <img class="logo" src="{{ $logoUrl }}" alt="{{ $snapshot['title'] }}">
                    @else
                        {{ $snapshot['title'] }}
                    @endif
                </a>
            </h1>
            @if (! empty($snapshot['generated_at']))
                <p>{{ __('status.last_updated', ['at' => $fmt->dateTime($snapshot['generated_at'])]) }}</p>
            @endif
        </div>
    </header>

    @yield('banner')

    <main id="main-content" class="wrap">
        @yield('content')
    </main>

    <footer>
        <div class="wrap">
        @if (! empty($brand['website_url']) || ! empty($brand['privacy_url']))
            <nav class="footer-links" aria-label="{{ __('status.footer_links') }}">
                @if (! empty($brand['website_url']))
                    <a href="{{ $brand['website_url'] }}" rel="noopener">{{ __('status.website') }}</a>
                @endif
                @if (! empty($brand['privacy_url']))
                    <a href="{{ $brand['privacy_url'] }}" rel="noopener">{{ __('status.privacy') }}</a>
                @endif
            </nav>
        @endif
        @if (($snapshot['subscriptions'] ?? false) && isset($slug))
            {{-- Email subscriptions (#92). Posts to the app host, also from custom domains; no CSRF token, since the page is cached. --}}
            <section class="subscribe" aria-labelledby="subscribe-title">
                <h2 id="subscribe-title">{{ __('status.subscribe_title') }}</h2>
                <p>{{ __('status.subscribe_lead') }}</p>
                <form method="post" action="{{ rtrim((string) config('app.url'), '/').'/status/'.$slug.'/subscribe' }}">
                    <div class="subscribe-row">
                        <label class="sr-only" for="subscribe-email">{{ __('status.subscribe_email') }}</label>
                        <input id="subscribe-email" name="email" type="email" required autocomplete="email" placeholder="{{ __('status.subscribe_email') }}">
                        <span class="hp" aria-hidden="true"><input name="website" type="text" tabindex="-1" autocomplete="off"></span>
                        <button type="submit">{{ __('status.subscribe_button') }}</button>
                    </div>
                </form>
            </section>
        @endif
        <p class="feed-link"><a href="{{ $base.'/feed' }}">{{ __('status.feed_link') }}</a></p>
        @if ($brand['show_powered_by'] ?? true)
            <p class="powered">{{ __('status.powered_by') }} <a href="{{ rtrim((string) config('app.url'), '/').'/' }}" rel="noopener">@include('site.partials.mark', ['hole' => 'var(--canvas)'])StatusConnect</a></p>
        @endif
        @if (config('legal.company_name'))
            {{-- Platform legal pages (#122), linked on the app host so they also work on custom domains. --}}
            @php $appUrl = rtrim((string) config('app.url'), '/'); @endphp
            @if (($snapshot['locale'] ?? '') === 'en')
                <p class="legal-links"><a href="{{ $appUrl.'/terms' }}">Terms of Use</a> · <a href="{{ $appUrl.'/privacy' }}">Privacy Policy</a></p>
            @else
                <p class="legal-links"><a href="{{ $appUrl.'/termos' }}" lang="pt-BR">Termos de Uso</a> · <a href="{{ $appUrl.'/privacidade' }}" lang="pt-BR">Política de Privacidade</a></p>
            @endif
        @endif
        </div>
    </footer>
    <script>setTimeout(function () { if (!document.hidden) { location.reload(); } }, 60000);</script>
</body>
</html>
