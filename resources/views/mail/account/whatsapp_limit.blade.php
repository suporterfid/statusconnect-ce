@extends('mail.account.layout')
@section('body')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.25;font-weight:700;letter-spacing:-0.01em;">{{ __('messages.mail_whatsapp_limit_title') }}</h1>
    @include('mail.partials.state', ['state' => 'down', 'label' => __('messages.mail_whatsapp_limit_state')])
    <p style="margin:0 0 12px;">{{ __('messages.mail_whatsapp_limit_body', ['organization' => $organization, 'limit' => $limit, 'plan' => $plan]) }}</p>
    <p style="margin:0 0 12px;color:#5F5F5F;">{{ __('messages.mail_whatsapp_limit_hint') }}</p>
@endsection
