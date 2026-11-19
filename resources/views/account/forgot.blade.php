@extends('account.layout')
@section('title', __('messages.page_forgot_title'))
@section('content')
    <h1>{{ __('messages.page_forgot_title') }}</h1>
    <p class="muted">{{ __('messages.page_forgot_intro') }}</p>
    <form method="post" action="{{ route('password.email') }}">
        @csrf
        <label for="email">{{ __('messages.page_field_email') }}</label>
        <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        @error('email')<div class="error" id="email-error">{{ $message }}</div>@enderror
        <button type="submit">{{ __('messages.page_forgot_submit') }}</button>
    </form>
    <p class="muted" style="margin-top:16px"><a href="{{ url('/app/login') }}">{{ __('messages.page_back_to_login') }}</a></p>
@endsection
