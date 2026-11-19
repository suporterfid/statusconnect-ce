<?php

namespace App\Application\GoogleLogin;

/** Sign in with Google (#244) settings, from config/google.php. */
final class GoogleLogin
{
    /** The button and the /auth/google routes work. */
    public static function enabled(): bool
    {
        return (bool) config('google.login_enabled', false)
            && trim((string) config('google.client_id', '')) !== ''
            && trim((string) config('google.client_secret', '')) !== '';
    }

    /**
     * The legal pages describe Google sign-in. True as soon as a client id is
     * set, even with login off, so Google can review the policy first.
     */
    public static function disclosed(): bool
    {
        return trim((string) config('google.client_id', '')) !== '';
    }

    public static function redirectUri(): string
    {
        $configured = trim((string) config('google.redirect_uri', ''));

        return $configured !== '' ? $configured : rtrim((string) config('app.url'), '/').'/auth/google/callback';
    }
}
