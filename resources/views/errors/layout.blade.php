{{--
    HTML error pages (DESIGN.md). Self-contained: no database or session access,
    so rendering a 500 cannot fail in turn. Root-relative assets so the page also
    works on a tenant's custom status domain. pt-BR first; English for /en paths
    or browsers that prefer English.
--}}
@php
    $status = (int) ($status ?? 500);
    $key = trans()->has("errors.{$status}.title", 'pt_BR') ? (string) $status : ($status >= 500 ? '5xx' : '4xx');
    $request = request();
    $preferred = strtolower((string) $request->getPreferredLanguage(['pt-BR', 'pt', 'en']));
    $locale = ($request->is('en') || $request->is('en/*') || str_starts_with($preferred, 'en')) ? 'en' : 'pt_BR';
    app()->setLocale($locale);
    $server = $status >= 500;
    $retry = $server || in_array($status, [419, 429], true);
    // Retry repeats a GET; after a failed form post (419, or any non-GET) it goes back to the page the form was on.
    $referer = (string) $request->headers->get('referer', '');
    $sameHost = $referer !== '' && parse_url($referer, PHP_URL_HOST) === $request->getHost();
    $retryUrl = ($request->isMethod('GET') && $status !== 419) ? $request->getRequestUri() : ($sameHost ? $referer : '/');
@endphp
<!doctype html>
<html lang="{{ $locale === 'en' ? 'en' : 'pt-BR' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __("errors.{$key}.title") }} · StatusConnect</title>
    @include('site.partials.head')
    <style>
        body { display: flex; flex-direction: column; min-block-size: 100vh; }
        .error-main { flex: 1; padding-block: clamp(40px, 8vh, 96px) 72px; }
        .error-main .wrap > * { max-inline-size: 720px; }
        .route-broken { display: flex; align-items: center; margin-block-end: 32px; inline-size: min(100%, 360px); }
        .route-broken .station { flex: none; inline-size: 18px; block-size: 18px; border-radius: 50%; background: var(--color-bg-canvas); box-shadow: inset 0 0 0 4px var(--line-ink); }
        .route-broken .seg { flex: 1; block-size: 4px; background: var(--line-ink); }
        .route-broken .gap { flex: 1; block-size: 0; border-block-start: 4px dashed var(--color-border-strong); }
        .route-broken .roundel { --size: 26px; }
        .error-code { margin: 0 0 8px; color: var(--color-text-secondary); font-weight: 600; font-variant-numeric: tabular-nums; }
        .error-main h1 { font-size: clamp(1.75rem, 1.3rem + 2vw, 2.5rem); letter-spacing: -0.025em; line-height: 1.15; }
        .error-main p.lead { margin: 14px 0 0; font-size: 1.125rem; color: var(--color-text-secondary); max-inline-size: 60ch; }
        .error-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-block-start: 28px; }
        .error-foot { padding-block: 20px; font-size: .875rem; }
        .error-foot p { margin: 0; color: var(--sign-muted); }
    </style>
</head>
<body>
    <header class="site-header sign">
        <div class="wrap">
            <a class="brand" href="/" aria-label="StatusConnect">@include('site.partials.mark')<span class="brand-word">Status<span>Connect</span></span></a>
        </div>
    </header>

    <main class="error-main" id="conteudo">
        <div class="wrap">
            <div class="route-broken" role="img" aria-label="{{ __('errors.route_label') }}">
                <span class="station"></span><span class="seg"></span><span class="station"></span><span class="gap"></span><span class="roundel {{ $server ? 'is-down' : 'is-slow' }}"></span>
            </div>
            <p class="error-code">{{ __('errors.code', ['code' => $status]) }}</p>
            <h1>{{ __("errors.{$key}.title") }}</h1>
            <p class="lead">{{ __("errors.{$key}.text") }}</p>
            <div class="error-actions">
                @if ($retry)
                    <a class="btn btn-primary" href="{{ $retryUrl }}">{{ __('errors.retry') }}</a>
                    <a class="btn btn-quiet" href="/">{{ __('errors.home') }}</a>
                @else
                    <a class="btn btn-primary" href="/">{{ __('errors.home') }}</a>
                @endif
            </div>
        </div>
    </main>

    <footer class="error-foot sign">
        <div class="wrap"><p>StatusConnect</p></div>
    </footer>
</body>
</html>
