<?php

namespace App\Infrastructure\Google;

use App\Application\GoogleLogin\GoogleIdentity;
use App\Application\GoogleLogin\GoogleLogin;
use App\Application\GoogleLogin\GoogleLoginException;
use Illuminate\Support\Facades\Http;

/**
 * Google OpenID Connect, authorization code flow with PKCE (#244).
 * Scopes are openid, email and profile only; the access token is never kept.
 */
final class GoogleOidcClient
{
    public function __construct(private readonly GoogleIdTokenVerifier $verifier)
    {
    }

    public function authorizationUrl(string $state, string $nonce, string $codeVerifier): string
    {
        return (string) config('google.authorization_endpoint').'?'.http_build_query([
            'client_id' => (string) config('google.client_id'),
            'redirect_uri' => GoogleLogin::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchanges the authorization code and returns the identity from the verified ID token. */
    public function exchange(string $code, string $codeVerifier, string $nonce): GoogleIdentity
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('google.timeout', 10))
                ->post((string) config('google.token_endpoint'), [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'code_verifier' => $codeVerifier,
                    'client_id' => (string) config('google.client_id'),
                    'client_secret' => (string) config('google.client_secret'),
                    'redirect_uri' => GoogleLogin::redirectUri(),
                ]);
        } catch (\Throwable $e) {
            throw new GoogleLoginException('failed', 'token request failed: '.$e->getMessage());
        }

        $idToken = $response->json('id_token');
        if (! $response->successful() || ! is_string($idToken) || $idToken === '') {
            throw new GoogleLoginException('failed', 'token endpoint answered HTTP '.$response->status().' '.(string) $response->json('error'));
        }

        return $this->verifier->verify($idToken, $nonce);
    }

    public static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
