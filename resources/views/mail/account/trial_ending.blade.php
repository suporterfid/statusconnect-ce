@extends('mail.account.layout')
@section('body')
    @include('mail.partials.state', ['state' => 'check', 'label' => __('messages.mail_trial_ending_title')])
    <p style="margin:0 0 12px;">{{ __('messages.mail_hello', ['name' => $name]) }}</p>
    <p style="margin:0;">{{ __('messages.mail_trial_ending_body', ['organization' => $organization, 'date' => $date]) }}</p>
    @include('mail.partials.button', ['url' => $url, 'label' => __('messages.mail_trial_button')])
@endsection
