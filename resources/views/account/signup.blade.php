@extends('account.layout')
@section('title', __('messages.page_signup_title'))
@section('content')
    <h1>{{ __('messages.page_signup_title') }}</h1>
    <p class="muted">{{ __($mode === 'approval' ? 'messages.page_signup_intro_approval' : 'messages.page_signup_intro', ['monitors' => config('plans.plans.free.monitors')]) }}</p>

    @if (\App\Application\GoogleLogin\GoogleLogin::enabled())
        @include('account.partials.google-button')
    @endif

    @if ($errors->any())
        <div class="errors" role="alert">{{ __('messages.page_fix_errors') }}</div>
    @endif

    <form method="post" action="{{ route('signup') }}" novalidate>
        @csrf
        <label for="name">{{ __('messages.page_field_name') }}</label>
        <input id="name" name="name" type="text" autocomplete="name" required value="{{ old('name') }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
        @error('name')<div class="error" id="name-error">{{ $message }}</div>@enderror

        <label for="email">{{ __('messages.page_field_email') }}</label>
        <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email', is_string(request()->query('email')) ? mb_substr(request()->query('email'), 0, 255) : '') }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        @error('email')<div class="error" id="email-error">{{ $message }}</div>@enderror

        <label for="organization">{{ __('messages.page_field_organization') }}</label>
        <input id="organization" name="organization" type="text" autocomplete="organization" required value="{{ old('organization') }}" @error('organization') aria-invalid="true" aria-describedby="organization-error" @enderror>
        @error('organization')<div class="error" id="organization-error">{{ $message }}</div>@enderror

        <label for="password">{{ __('messages.page_field_password') }}</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required minlength="10" aria-describedby="password-hint @error('password') password-error @enderror">
        <div class="muted" id="password-hint">{{ __('messages.page_password_hint') }}</div>
        @error('password')<div class="error" id="password-error">{{ $message }}</div>@enderror

        <label for="password_confirmation">{{ __('messages.page_field_password_confirmation') }}</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>

        <div class="hp" aria-hidden="true">
            <label for="website">Website</label>
            <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <label class="check" for="accept_terms">
            <input id="accept_terms" name="accept_terms" type="checkbox" value="1" required @checked(old('accept_terms'))>
            <span>{!! __('messages.page_accept_terms', ['terms' => '<a href="'.e(url(app()->getLocale() === 'en' ? '/terms' : '/termos')).'" target="_blank" rel="noopener">'.e(__('messages.page_terms')).'</a>', 'privacy' => '<a href="'.e(url(app()->getLocale() === 'en' ? '/privacy' : '/privacidade')).'" target="_blank" rel="noopener">'.e(__('messages.page_privacy')).'</a>']) !!}</span>
        </label>
        @error('accept_terms')<div class="error">{{ $message }}</div>@enderror

        <button type="submit">{{ __('messages.page_signup_submit') }}</button>
    </form>
    <p class="muted" style="margin-block-start:16px">{{ __('messages.page_have_account') }} <a href="{{ url('/app/login') }}">{{ __('messages.page_login') }}</a></p>
@endsection

@section('styles')
    .route { list-style: none; margin: 20px 0 0; padding: 0; }
    .route li { position: relative; padding: 0 0 28px 52px; }
    .route li::before { content: attr(data-n); position: absolute; inset-inline-start: 0; inset-block-start: 0; inline-size: 34px; block-size: 34px; border-radius: 50%; background: var(--color-bg-canvas); box-shadow: inset 0 0 0 4px var(--line-ink); display: grid; place-items: center; font-weight: 700; font-variant-numeric: tabular-nums; z-index: 1; }
    .route li.is-here::before { background: var(--line-ink); color: var(--color-bg-canvas); }
    .route li::after { content: ""; position: absolute; inset-inline-start: 15px; inset-block: 17px -17px; inline-size: 4px; background: var(--line-ink); }
    .route li:last-child { padding-block-end: 0; }
    .route li:last-child::after { display: none; }
    .route h3 { font-size: 1.0625rem; line-height: 1.3; padding-block-start: 6px; margin: 0; }
    .route p { margin: 4px 0 0; color: var(--color-text-secondary); font-size: .9375rem; }
    .aside-title { font-size: 1.25rem; letter-spacing: -0.01em; margin: 0; }
    @media (min-width: 900px) { .account aside { padding-block-start: 32px; } }
@endsection

@section('aside')
    @php $steps = __('messages.page_signup_route'); if ($mode !== 'approval') { $steps = array_values(array_filter($steps, fn ($s) => ($s['approval_only'] ?? false) === false)); } @endphp
    <h2 class="aside-title">{{ __('messages.page_signup_route_title') }}</h2>
    <ol class="route">
        @foreach ($steps as $i => $step)
            <li data-n="{{ $i + 1 }}" class="{{ $i === 0 ? 'is-here' : '' }}" @if ($i === 0) aria-current="step" @endif>
                <h3>{{ $step["title"] }}</h3>
                <p>{{ $step['text'] }}</p>
            </li>
        @endforeach
    </ol>
@endsection
