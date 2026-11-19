{{-- Sign in with Google (#244), following Google's branding guidelines: the standard "G" mark, unchanged, on the light or dark neutral button. --}}
<a class="gsi-button" href="{{ route('google.redirect') }}">
    @include('account.partials.google-mark')
    <span>{{ __('messages.page_google_continue') }}</span>
</a>
<div class="gsi-divider"><span>{{ __('messages.page_google_or_email') }}</span></div>
