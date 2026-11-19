{{-- Default home page of the Community Edition (#250). The enterprise edition replaces it with its landing page. --}}
@extends('site.layout')

@php
    $en = $locale === 'en';
    $name = config('app.name') ?: 'StatusConnect';
@endphp

@section('title', $name)

@section('language_switch')
    <a href="{{ $en ? url('/') : url('/en') }}" hreflang="{{ $en ? 'pt-BR' : 'en' }}" lang="{{ $en ? 'pt-BR' : 'en' }}">{{ $en ? 'Português' : 'English' }}</a>
@endsection

@section('styles')
    .home { padding-block: 72px 80px; }
    .home h1 { font-size: clamp(2rem, 5.2vw, 2.75rem); line-height: 1.15; letter-spacing: -0.025em; }
    .home p { margin: 16px 0 0; font-size: 1.125rem; line-height: 1.6; color: var(--color-text-secondary); max-inline-size: 52ch; }
    .home .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-block-start: 28px; }
@endsection

@section('content')
    <section class="home">
        <div class="wrap">
            <h1>{{ $name }}</h1>
            <p>{{ $en ? 'Uptime monitoring and public status pages.' : 'Monitoramento de disponibilidade e páginas de status públicas.' }}</p>
            <div class="actions">
                <a class="btn btn-primary" href="{{ url('/app/login') }}">{{ $en ? 'Sign in' : 'Entrar' }}</a>
                @if ($signupUrl)
                    <a class="btn btn-quiet" href="{{ $signupUrl }}">{{ $en ? 'Create an account' : 'Criar conta' }}</a>
                @endif
            </div>
        </div>
    </section>
@endsection
