@extends('mail.subscriber.layout')
@section('message')
    @if ($status)
        <p style="margin:0 0 6px;"><span style="display:inline-block;padding:2px 10px;border:1px solid #8A8882;border-radius:999px;font-size:13px;font-weight:600;color:#252525;">{{ __('status.update_statuses.'.$status) }}</span></p>
    @endif
    <p style="margin:0 0 8px;font-size:20px;line-height:1.3;font-weight:700;">{{ $title }}</p>
    <div style="white-space:pre-line;">{{ $body }}</div>
    <p style="margin:12px 0 0;color:#5F5F5F;font-size:14px;">{{ __('status.subscribe_mail_published', ['at' => $publishedAt, 'tz' => $timezone]) }}</p>
@endsection
