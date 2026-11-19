@extends('site.layout')

@section('title', $title.' · StatusConnect')

@section('language_switch')
    <a href="{{ $alternateUrl }}" hreflang="{{ $locale === 'en' ? 'pt-BR' : 'en' }}" lang="{{ $locale === 'en' ? 'pt-BR' : 'en' }}">{{ $locale === 'en' ? 'Português' : 'English' }}</a>
@endsection

@section('head')
    <link rel="alternate" hreflang="{{ $locale === 'en' ? 'pt-BR' : 'en' }}" href="{{ $alternateUrl }}">
@endsection

@section('styles')
    .legal-head { background: var(--color-bg-surface); border-block-end: 1px solid var(--color-border-default); padding-block: 40px 32px; }
    .legal-head h1 { font-size: clamp(2rem, 5vw, 2.75rem); line-height: 1.15; letter-spacing: -0.02em; max-inline-size: var(--measure); margin-inline: auto; }
    .legal-head .translation-note { max-inline-size: var(--measure); margin: 12px auto 0; color: var(--color-text-secondary); font-size: .9375rem; }
    .legal-head .updated { max-inline-size: var(--measure); margin: 8px auto 0; color: var(--color-text-secondary); font-size: .9375rem; }
    .legal { max-inline-size: var(--measure); margin: 32px auto 0; font-size: 1.0625rem; line-height: 1.65; }
    .legal h1 { display: none; }
    .legal h2 { margin: 48px 0 12px; font-size: 1.5rem; line-height: 1.3; letter-spacing: -0.01em; }
    .legal h3 { margin: 28px 0 8px; font-size: 1.25rem; line-height: 1.35; }
    .legal p, .legal li { max-inline-size: 70ch; }
    .legal ul, .legal ol { padding-inline-start: 1.25rem; }
    .legal li { margin-block: 6px; }
    .legal li::marker { color: var(--color-text-secondary); }
    .legal .table-scroll { overflow-x: auto; border: 1px solid var(--color-border-default); border-radius: 6px; }
    .legal table { border-collapse: collapse; inline-size: 100%; font-size: .9375rem; line-height: 1.5; }
    .legal th { background: var(--sign-bg); color: var(--sign-text); font-weight: 600; }
    .legal th, .legal td { text-align: start; vertical-align: top; padding: 10px 12px; }
    .legal td { border-block-start: 1px solid var(--color-border-default); }
@endsection

@section('content')
    <article aria-labelledby="legal-title">
        <header class="legal-head">
            <div class="wrap">
                <h1 id="legal-title">{{ $title }}</h1>
                @if ($updatedAt)
                    <p class="updated">{{ __('landing.updated_at', ['date' => $updatedAt]) }}</p>
                @endif
                @if ($locale === 'en')
                    <p class="translation-note">This is a translation. If it conflicts with the <a href="{{ $alternateUrl }}" hreflang="pt-BR" lang="pt-BR">Portuguese version</a>, the Portuguese version prevails.</p>
                @endif
            </div>
        </header>
        <div class="wrap">
            <div class="legal">
                {!! $html !!}
            </div>
        </div>
    </article>
@endsection
