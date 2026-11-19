@extends('account.layout')
@section('title', __('messages.page_reset_title'))
@section('content')
    <h1>{{ __('messages.page_reset_title') }}</h1>
    <form method="post" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="email">{{ __('messages.page_field_email') }}</label>
        <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email', $email) }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        @error('email')<div class="error" id="email-error">{{ $message }}</div>@enderror

        <label for="password">{{ __('messages.page_field_new_password') }}</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required minlength="10" aria-describedby="password-hint @error('password') password-error @enderror">
        <div class="muted" id="password-hint">{{ __('messages.page_password_hint') }}</div>
        @error('password')<div class="error" id="password-error">{{ $message }}</div>@enderror

        <label for="password_confirmation">{{ __('messages.page_field_password_confirmation') }}</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        <button type="submit">{{ __('messages.page_reset_submit') }}</button>
    </form>
@endsection
