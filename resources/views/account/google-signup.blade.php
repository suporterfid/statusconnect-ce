@extends('account.layout')
@section('title', __('messages.page_google_signup_title'))
@section('content')
    {{-- Sign-up with a Google identity (#244): Google verified the address, so only the organization and the terms are asked. --}}
    <h1>{{ __('messages.page_google_signup_title') }}</h1>
    <p class="muted">{{ __('messages.page_google_signup_intro', ['email' => $identity->email]) }}</p>

    @if ($errors->any())
        <div class="errors" role="alert">{{ $errors->has('email') ? $errors->first('email') : __('messages.page_fix_errors') }}</div>
    @endif

    <form method="post" action="{{ route('signup.google') }}" novalidate>
        @csrf
        <label for="email">{{ __('messages.page_field_email') }}</label>
        <input id="email" type="email" value="{{ $identity->email }}" readonly aria-readonly="true">

        <label for="name">{{ __('messages.page_field_name') }}</label>
        <input id="name" name="name" type="text" autocomplete="name" required value="{{ old('name', $identity->name) }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
        @error('name')<div class="error" id="name-error">{{ $message }}</div>@enderror

        <label for="organization">{{ __('messages.page_field_organization') }}</label>
        <input id="organization" name="organization" type="text" autocomplete="organization" required value="{{ old('organization') }}" @error('organization') aria-invalid="true" aria-describedby="organization-error" @enderror>
        @error('organization')<div class="error" id="organization-error">{{ $message }}</div>@enderror

        <label class="check" for="accept_terms">
            <input id="accept_terms" name="accept_terms" type="checkbox" value="1" required @checked(old('accept_terms'))>
            <span>{!! __('messages.page_accept_terms', ['terms' => '<a href="'.e(url(app()->getLocale() === 'en' ? '/terms' : '/termos')).'" target="_blank" rel="noopener">'.e(__('messages.page_terms')).'</a>', 'privacy' => '<a href="'.e(url(app()->getLocale() === 'en' ? '/privacy' : '/privacidade')).'" target="_blank" rel="noopener">'.e(__('messages.page_privacy')).'</a>']) !!}</span>
        </label>
        @error('accept_terms')<div class="error">{{ $message }}</div>@enderror

        <button type="submit">{{ __('messages.page_signup_submit') }}</button>
    </form>
    <p class="muted" style="margin-block-start:16px">{{ __('messages.page_google_signup_other') }} <a href="{{ route('signup') }}">{{ __('messages.page_signup_title') }}</a></p>
@endsection
