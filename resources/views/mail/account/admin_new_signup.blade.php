@extends('mail.account.layout')
@section('body')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.25;font-weight:700;letter-spacing:-0.01em;">{{ __('messages.mail_admin_signup_title') }}</h1>
    @include('mail.partials.state', ['state' => 'check', 'label' => __('messages.mail_admin_signup_state')])
    <p style="margin:0 0 12px;">{{ __('messages.mail_admin_signup_body', ['name' => $name, 'email' => $email, 'organization' => $organization]) }}</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-top:1px solid #D9D7D3;">
        @foreach ([__('messages.mail_field_name') => $name, __('messages.mail_field_email') => $email, __('messages.mail_field_org') => $organization] as $field => $value)
            <tr>
                <td style="padding:8px 12px 8px 0;border-bottom:1px solid #D9D7D3;font-size:14px;color:#5F5F5F;width:120px;">{{ $field }}</td>
                <td style="padding:8px 0;border-bottom:1px solid #D9D7D3;font-size:14px;font-weight:600;word-break:break-all;">{{ $value }}</td>
            </tr>
        @endforeach
    </table>
    @include('mail.partials.button', ['url' => $url, 'label' => __('messages.mail_admin_signup_button')])
@endsection
