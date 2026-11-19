<?php

namespace App\Infrastructure\Google;

use App\Application\GoogleLogin\GoogleIdentity;
use App\Application\GoogleLogin\GoogleLoginException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies a Google ID token (#244): RS256 signature against Google's JWKS,
 * then iss, aud/azp, exp, iat and nonce. The keys are cached in the
 * application cache (file or database store) for as long as Google's
 * Cache-Control allows, and fetched again once when a token names an
 * unknown key id (Google rotates its keys).
 */
final class GoogleIdTokenVerifier
{
    private const CACHE_KEY = 'google-login:jwks';

    public function verify(string $idToken, string $expectedNonce): GoogleIdentity
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new GoogleLoginException('failed', 'id_token is not a JWS');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode(self::base64UrlDecode($encodedHeader), true);
        $claims = json_decode(self::base64UrlDecode($encodedPayload), true);
        $signature = self::base64UrlDecode($encodedSignature);
        if (! is_array($header) || ! is_array($claims) || $signature === '') {
            throw new GoogleLoginException('failed', 'id_token is malformed');
        }
        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new GoogleLoginException('failed', 'id_token alg or kid not accepted');
        }

        $key = $this->publicKey($header['kid']);
        if (openssl_verify($encodedHeader.'.'.$encodedPayload, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new GoogleLoginException('failed', 'id_token signature invalid');
        }

        $this->assertClaims($claims, $expectedNonce);

        $emailVerified = $claims['email_verified'] ?? false;

        return new GoogleIdentity(
            sub: (string) $claims['sub'],
            email: mb_strtolower(trim((string) $claims['email'])),
            emailVerified: $emailVerified === true || $emailVerified === 'true',
            name: trim((string) ($claims['name'] ?? '')),
        );
    }

    /** @param array<string, mixed> $claims */
    private function assertClaims(array $claims, string $expectedNonce): void
    {
        $clientId = (string) config('google.client_id');
        $now = time();
        $leeway = (int) config('google.leeway', 60);

        if (! in_array($claims['iss'] ?? null, (array) config('google.issuers'), true)) {
            throw new GoogleLoginException('failed', 'id_token iss mismatch');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array($clientId, $audiences, true)) {
            throw new GoogleLoginException('failed', 'id_token aud mismatch');
        }
        // With several audiences, the authorized party must be this client.
        if ((count($audiences) > 1 || isset($claims['azp'])) && ($claims['azp'] ?? null) !== $clientId) {
            throw new GoogleLoginException('failed', 'id_token azp mismatch');
        }

        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] < $now - $leeway) {
            throw new GoogleLoginException('failed', 'id_token expired');
        }
        if (isset($claims['iat']) && (! is_numeric($claims['iat']) || (int) $claims['iat'] > $now + $leeway)) {
            throw new GoogleLoginException('failed', 'id_token issued in the future');
        }

        $nonce = $claims['nonce'] ?? null;
        if (! is_string($nonce) || $expectedNonce === '' || ! hash_equals($expectedNonce, $nonce)) {
            throw new GoogleLoginException('failed', 'id_token nonce mismatch');
        }

        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255) {
            throw new GoogleLoginException('failed', 'id_token sub missing');
        }
        if (! is_string($claims['email'] ?? null) || ! filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
            throw new GoogleLoginException('failed', 'id_token email missing');
        }
    }

    /** @return \OpenSSLAsymmetricKey */
    private function publicKey(string $kid)
    {
        $keys = Cache::get(self::CACHE_KEY);
        if (! is_array($keys) || ! isset($keys[$kid])) {
            $keys = $this->fetchKeys();
        }
        if (! isset($keys[$kid])) {
            throw new GoogleLoginException('failed', 'id_token kid unknown');
        }

        $key = openssl_pkey_get_public($keys[$kid]);
        if ($key === false) {
            throw new GoogleLoginException('failed', 'JWKS key unusable');
        }

        return $key;
    }

    /** @return array<string, string> kid => PEM public key */
    private function fetchKeys(): array
    {
        try {
            $response = Http::timeout((int) config('google.timeout', 10))->acceptJson()->get((string) config('google.jwks_uri'));
        } catch (\Throwable $e) {
            throw new GoogleLoginException('failed', 'JWKS fetch failed: '.$e->getMessage());
        }
        if (! $response->successful() || ! is_array($response->json('keys'))) {
            throw new GoogleLoginException('failed', 'JWKS fetch failed: HTTP '.$response->status());
        }

        $keys = [];
        foreach ($response->json('keys') as $jwk) {
            if (is_array($jwk) && ($jwk['kty'] ?? null) === 'RSA' && is_string($jwk['kid'] ?? null)
                && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)
                && (($jwk['use'] ?? 'sig') === 'sig')) {
                $keys[$jwk['kid']] = self::rsaPem(self::base64UrlDecode($jwk['n']), self::base64UrlDecode($jwk['e']));
            }
        }

        $ttl = 3600;
        if (preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $m) === 1) {
            $ttl = max(60, min(86400, (int) $m[1]));
        }
        if ($keys !== []) {
            Cache::put(self::CACHE_KEY, $keys, $ttl);
        }

        return $keys;
    }

    /** SubjectPublicKeyInfo PEM for an RSA modulus and exponent (big-endian bytes). */
    public static function rsaPem(string $modulus, string $exponent): string
    {
        $rsaPublicKey = self::der(0x30, self::derInteger($modulus).self::derInteger($exponent));
        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1), NULL parameters.
        $algorithm = self::der(0x30, self::der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01").self::der(0x05, ''));
        $spki = self::der(0x30, $algorithm.self::der(0x03, "\x00".$rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return self::der(0x02, $bytes);
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            return chr($tag).chr($length).$content;
        }
        $lengthBytes = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(0x80 | strlen($lengthBytes)).$lengthBytes.$content;
    }

    public static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }
}
