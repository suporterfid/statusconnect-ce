@extends('mail.account.layout')
@section('body')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.25;font-weight:700;letter-spacing:-0.01em;">{{ __('messages.mail_verify_title') }}</h1>
    <p style="margin:0 0 12px;">{{ __('messages.mail_hello', ['name' => $name]) }}</p>
    <p style="margin:0;">{{ __('messages.mail_verify_body') }}</p>
    @include('mail.partials.button', ['url' => $url, 'label' => __('messages.mail_verify_button')])
    <p style="margin:0 0 16px;color:#5F5F5F;font-size:14px;">{{ __('messages.mail_verify_expiry', ['hours' => $hours]) }}</p>
    @include('mail.partials.link-fallback', ['url' => $url])
@endsection
