<?php

/*
 * Sign in with Google (#244): OpenID Connect, authorization code flow with
 * state, nonce and PKCE. Plain HTTPS calls through Laravel's HTTP client, so
 * it runs on shared hosting. Setup: docs/integrations/google-login.md
 *
 * The button and the routes exist only when login_enabled is true and both
 * the client id and secret are set. The legal pages describe Google sign-in
 * as soon as the client id is set, so Google can review the privacy policy
 * before the consent screen is published.
 */
return [
    'login_enabled' => (bool) env('GOOGLE_LOGIN_ENABLED', false),
    'client_id' => (string) env('GOOGLE_CLIENT_ID', ''),
    'client_secret' => (string) env('GOOGLE_CLIENT_SECRET', ''),
    // Must match the redirect URI registered in Google Cloud. Empty uses APP_URL/auth/google/callback.
    'redirect_uri' => (string) env('GOOGLE_REDIRECT_URI', ''),

    // Google's OpenID Connect endpoints (https://accounts.google.com/.well-known/openid-configuration).
    'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token_endpoint' => 'https://oauth2.googleapis.com/token',
    'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
    'issuers' => ['https://accounts.google.com', 'accounts.google.com'],

    // Seconds for each call to Google, and clock skew tolerated on the ID token.
    'timeout' => (int) env('GOOGLE_HTTP_TIMEOUT', 10),
    'leeway' => 60,
];
