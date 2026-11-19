@extends('mail.account.layout')
@section('body')
    @include('mail.partials.state', ['state' => 'ok', 'label' => __('messages.mail_approved_title')])
    <p style="margin:0 0 12px;">{{ __('messages.mail_hello', ['name' => $name]) }}</p>
    <p style="margin:0;">{{ __('messages.mail_approved_body', ['organization' => $organization]) }}</p>
    @include('mail.partials.button', ['url' => $url, 'label' => __('messages.page_login')])
@endsection
