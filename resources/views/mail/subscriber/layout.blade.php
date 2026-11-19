{{-- Status page subscriber emails (#92): the band is the tenant's, StatusConnect is only credited when powered-by is on. --}}
@extends('mail.layout')

@section('band_title'){{ $pageTitle }}@endsection
@if (! empty($accent) && ! empty($accentText))
    @section('band_bg'){{ $accent }}@endsection
    @section('band_text'){{ $accentText }}@endsection
@endif

@section('body')
    @yield('message')
    <p style="margin:24px 0 0;"><a href="{{ $pageUrl }}" style="color:#0F5EAB;font-weight:600;">{{ __('status.subscribe_mail_view_page') }}</a></p>
@endsection

@section('footer')
    @if ($unsubscribeUrl)
        <p style="margin:0;">{{ __('status.subscribe_mail_footer', ['page' => $pageTitle]) }} <a href="{{ $unsubscribeUrl }}" style="color:#5F5F5F;">{{ __('status.subscribe_mail_unsubscribe') }}</a></p>
    @endif
    @if ($poweredBy ?? true)
        <p style="margin:8px 0 0;">{{ __('status.powered_by') }} <a href="{{ rtrim((string) config('app.url'), '/').'/' }}" style="color:#252525;font-weight:600;text-decoration:none;">StatusConnect</a></p>
    @endif
@endsection
