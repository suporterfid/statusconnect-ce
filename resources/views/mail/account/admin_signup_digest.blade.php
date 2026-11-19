@extends('mail.account.layout')
@section('body')
    <h1 style="margin:0 0 12px;font-size:22px;line-height:1.25;font-weight:700;letter-spacing:-0.01em;">{{ __('messages.mail_signup_digest_title') }}</h1>
    <p style="margin:0 0 12px;">{{ __('messages.mail_signup_digest_body') }}</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-top:1px solid #D9D7D3;">
        @foreach ($rows as $row)
            <tr>
                <td style="padding:8px 12px 8px 0;border-bottom:1px solid #D9D7D3;font-size:14px;color:#5F5F5F;width:80px;vertical-align:top;">{{ $row['at'] }}</td>
                <td style="padding:8px 0;border-bottom:1px solid #D9D7D3;font-size:14px;word-break:break-all;">
                    <strong>{{ $row['organization'] }}</strong><br>
                    {{ $row['name'] }} · {{ $row['email'] }}<br>
                    <span style="color:#5F5F5F;">
                        {{ __($row['verified'] ? 'messages.mail_signup_digest_verified' : 'messages.mail_signup_digest_unverified') }}
                        · {{ __('messages.mail_signup_digest_monitors', ['count' => $row['monitors']]) }}
                        @if ($row['trial']) · {{ __('messages.mail_signup_digest_trial') }} @endif
                        @if ($row['suspended']) · {{ __('messages.mail_signup_digest_suspended') }} @endif
                    </span>
                </td>
            </tr>
        @endforeach
    </table>
    @include('mail.partials.button', ['url' => $url, 'label' => __('messages.mail_admin_signup_button')])
@endsection
