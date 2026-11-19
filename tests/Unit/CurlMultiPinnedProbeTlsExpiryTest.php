<?php

namespace Tests\Unit;

use App\Infrastructure\HttpClient\CurlMultiPinnedProbe;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for a bug where the tls_expires_in_days assertion always
 * failed: CURLOPT_CERTINFO was never enabled and CheckOutcome::tlsExpiresAt was
 * never populated from the cURL response, so the assertion evaluator treated
 * every HTTPS monitor as if its certificate expiry were unknown.
 */
class CurlMultiPinnedProbeTlsExpiryTest extends TestCase
{
    public function test_parses_the_leaf_certificate_expire_date(): void
    {
        $certInfo = [
            ['Subject' => 'CN=example.com', 'Expire date' => 'Aug 13 23:59:59 2026 GMT'],
        ];

        $expiry = CurlMultiPinnedProbe::parseTlsExpiryFromCertInfo($certInfo);

        $this->assertNotNull($expiry);
        $this->assertSame('2026-08-13', $expiry->format('Y-m-d'));
    }

    public function test_returns_null_when_certinfo_is_empty(): void
    {
        $this->assertNull(CurlMultiPinnedProbe::parseTlsExpiryFromCertInfo([]));
    }

    public function test_returns_null_when_expire_date_field_is_missing(): void
    {
        $certInfo = [['Subject' => 'CN=example.com']];

        $this->assertNull(CurlMultiPinnedProbe::parseTlsExpiryFromCertInfo($certInfo));
    }

    public function test_returns_null_for_an_unparseable_expire_date(): void
    {
        $certInfo = [['Expire date' => 'not-a-date']];

        $this->assertNull(CurlMultiPinnedProbe::parseTlsExpiryFromCertInfo($certInfo));
    }
}
