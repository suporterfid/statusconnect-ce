@extends('account.layout')
@section('title', $title)
@section('content')
    <h1>{{ $title }}</h1>
    <p>{{ $body }}</p>
    @if ($loginLink)
        <a class="button" href="{{ url('/app/login') }}">{{ __('messages.page_login') }}</a>
    @endif
    @if ($showResend)
        <form method="post" action="{{ route('signup.resend') }}">
            @csrf
            <label for="email">{{ __('messages.page_resend_label') }}</label>
            <input id="email" name="email" type="email" autocomplete="email" required>
            <button type="submit">{{ __('messages.page_resend_submit') }}</button>
        </form>
    @endif
@endsection
