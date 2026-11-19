<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name') }}</title>
@include('site.partials.head')
    <style>
        .account { padding-block: 40px 0; }
        .account .wrap { display: grid; gap: 32px; align-items: start; max-inline-size: 1000px; }
        .account.has-aside .wrap { grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 900px) { .account.has-aside .wrap { grid-template-columns: minmax(0, 7fr) minmax(0, 5fr); gap: 56px; } }
        .account.no-aside .wrap { max-inline-size: 520px; }
        .card { background: var(--color-bg-elevated); border: 1px solid var(--color-border-default); border-radius: 6px; padding: 24px; box-shadow: var(--shadow-1); }
        @media (min-width: 480px) { .card { padding: 32px; } }
        .card h1 { font-size: clamp(1.625rem, 4vw, 2rem); line-height: 1.2; letter-spacing: -0.02em; margin-block-end: 8px; }
        .card p { margin: 0 0 16px; }
        .muted { color: var(--color-text-secondary); font-size: .9375rem; }
        .card label { display: block; font-weight: 600; font-size: .9375rem; margin: 18px 0 6px; }
        .card input[type=text], .card input[type=email], .card input[type=password] { inline-size: 100%; min-block-size: 44px; padding: 10px 12px; border: 1px solid var(--color-border-strong); border-radius: 4px; background: var(--color-bg-elevated); color: var(--color-text-primary); font: inherit; transition: box-shadow 120ms var(--ease), border-color 120ms var(--ease); }
        .card input:focus-visible { outline: none; border-color: var(--color-focus-ring); box-shadow: 0 0 0 2px var(--color-focus-ring); }
        .card input[aria-invalid="true"] { border-color: var(--color-danger-fg); box-shadow: 0 0 0 1px var(--color-danger-fg); }
        .card .check { display: flex; gap: 10px; align-items: flex-start; margin-block-start: 20px; font-weight: 400; }
        .card .check input { inline-size: 18px; block-size: 18px; margin-block-start: 3px; flex: none; }
        .card button, .card .button { display: inline-flex; align-items: center; justify-content: center; inline-size: 100%; min-block-size: 48px; margin-block-start: 24px; padding: 10px 16px; border: 0; border-radius: 4px; background: var(--color-action-primary); color: var(--color-action-primary-content); font: 600 1rem/1.25 var(--font-ui); text-decoration: none; cursor: pointer; transition: background-color 120ms var(--ease); }
        .card button:hover, .card .button:hover { background: var(--color-action-primary-hover); }
        .card button:active { background: var(--color-action-primary-active); }
        .error { color: var(--color-danger-fg); font-size: .875rem; font-weight: 500; margin-block-start: 6px; }
        .errors { background: var(--color-danger-bg); border: 1px solid var(--color-danger-border); color: var(--color-danger-fg); border-radius: 4px; padding: 10px 12px; margin-block-end: 8px; font-weight: 500; }
        .hp { position: absolute; inset-inline-start: -10000px; inline-size: 1px; block-size: 1px; overflow: hidden; }
        .account-foot { margin-block-start: 48px; }
        /* Sign in with Google (#244): Google's neutral button, light and dark. */
        .gsi-button { display: flex; align-items: center; justify-content: center; gap: 10px; inline-size: 100%; min-block-size: 44px; margin-block-start: 20px; padding: 0 12px; border: 1px solid #747775; border-radius: 4px; background: #FFFFFF; color: #1F1F1F; font: 500 .9375rem/1.25 var(--font-ui); text-decoration: none; transition: background-color 120ms var(--ease); }
        .gsi-button:hover { background: #F2F2F2; color: #1F1F1F; }
        .gsi-button:focus-visible { outline: 2px solid var(--color-focus-ring); outline-offset: 2px; }
        .gsi-mark { flex: none; }
        @media (prefers-color-scheme: dark) { .gsi-button { background: #131314; border-color: #8E918F; color: #E3E3E3; } .gsi-button:hover { background: #1F1F21; color: #E3E3E3; } }
        .gsi-divider { display: flex; align-items: center; gap: 12px; margin-block: 20px 4px; color: var(--color-text-secondary); font-size: .875rem; }
        .gsi-divider::before, .gsi-divider::after { content: ""; flex: 1; border-block-start: 1px solid var(--color-border-default); }
        @yield('styles')
    </style>
</head>
<body>
<a class="skip-link" href="#content">{{ __('messages.page_skip') }}</a>
<header class="site-header sign">
    <div class="wrap">
        <a class="brand" href="{{ url('/') }}" aria-label="StatusConnect">@include('site.partials.mark')<span class="brand-word">Status<span>Connect</span></span></a>
        <nav class="site-nav" aria-label="{{ __('messages.page_skip') }}">
            <ul><li><a class="nav-login" href="{{ url('/app/login') }}">{{ __('messages.page_login') }}</a></li></ul>
        </nav>
    </div>
</header>
<main id="content" class="account {{ View::hasSection('aside') ? 'has-aside' : 'no-aside' }}">
    <div class="wrap">
        <div class="card">
            @yield('content')
        </div>
        @hasSection('aside')
            <aside>@yield('aside')</aside>
        @endif
    </div>
</main>
<footer class="site-footer sign">
    <div class="wrap">
        <p>© {{ date('Y') }} {{ config('legal.company_name') ?: config('app.name') }}</p>
        <nav aria-label="{{ __('messages.page_terms') }}">
            <ul>
                <li><a href="{{ url(app()->getLocale() === 'en' ? '/terms' : '/termos') }}">{{ __('messages.page_terms') }}</a></li>
                <li><a href="{{ url(app()->getLocale() === 'en' ? '/privacy' : '/privacidade') }}">{{ __('messages.page_privacy') }}</a></li>
            </ul>
        </nav>
    </div>
</footer>
</body>
</html>
