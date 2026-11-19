@extends('mail.subscriber.layout')
@section('message')
    <p style="margin:0 0 4px;">{{ __('status.subscribe_mail_confirm_body', ['page' => $pageTitle]) }}</p>
    @include('mail.partials.button', ['url' => $url, 'label' => __('status.subscribe_confirm_button')])
    <p style="margin:0;color:#5F5F5F;font-size:14px;">{{ __('status.subscribe_mail_confirm_ignore', ['hours' => $hours]) }}</p>
@endsection
