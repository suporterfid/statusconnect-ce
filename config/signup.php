<?php

/*
 * Public sign-up (#120).
 *
 * mode: closed   no sign-up page (default; accounts come from the CLI or the platform console)
 *       approval sign-up open, a platform admin approves each new organization
 *       open     sign-up open, the organization is usable once the email is verified
 */
return [
    'mode' => env('SIGNUP_MODE', 'closed'),

    'locale' => env('SIGNUP_LOCALE', 'pt_BR'),
    'timezone' => env('SIGNUP_TIMEZONE', 'America/Sao_Paulo'),

    // Hours a verification link stays valid.
    'verification_hours' => (int) env('SIGNUP_VERIFICATION_HOURS', 48),

    // Disposable email domains refused at sign-up. Extend with SIGNUP_BLOCKED_DOMAINS (comma separated).
    'blocked_domains' => array_values(array_filter(array_map('trim', array_merge([
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com',
        'temp-mail.org', 'tempmail.com', 'yopmail.com', 'trashmail.com', 'getnada.com', 'dispostable.com',
        'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mailnesia.com', 'mintemail.com', 'emailondeck.com',
        'tempail.com', 'moakt.com', 'mohmal.com',
    ], explode(',', (string) env('SIGNUP_BLOCKED_DOMAINS', '')))))),

];
