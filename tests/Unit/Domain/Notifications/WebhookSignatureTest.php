<?php

namespace Tests\Unit\Domain\Notifications;

use App\Domain\Notifications\ValueObjects\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    public function test_it_creates_valid_signature_header(): void
    {
        $body = '{"event":"opened","incident_id":"inc_123"}';
        $secret = 'super-secret-key';
        $timestamp = 1700000000;

        $header = WebhookSignature::create($body, $secret, $timestamp);

        $expectedHash = hash_hmac('sha256', '1700000000.{"event":"opened","incident_id":"inc_123"}', $secret);
        $this->assertEquals("t=1700000000,v1={$expectedHash}", $header);
    }

    public function test_it_verifies_valid_signature(): void
    {
        $body = '{"event":"resolved"}';
        $secret = 'my-secret';
        $now = 1700000000;
        $header = WebhookSignature::create($body, $secret, $now);

        $result = WebhookSignature::verify($header, $body, $secret, $now);

        $this->assertTrue($result);
    }

    public function test_it_rejects_tampered_body(): void
    {
        $body = '{"event":"resolved"}';
        $tamperedBody = '{"event":"opened"}';
        $secret = 'my-secret';
        $now = 1700000000;
        $header = WebhookSignature::create($body, $secret, $now);

        $result = WebhookSignature::verify($header, $tamperedBody, $secret, $now);

        $this->assertFalse($result);
    }

    public function test_it_rejects_expired_timestamp_skew(): void
    {
        $body = '{"event":"resolved"}';
        $secret = 'my-secret';
        $timestamp = 1700000000;
        $header = WebhookSignature::create($body, $secret, $timestamp);

        // Current time is 400 seconds later (max skew is 300s)
        $now = 1700000400;

        $result = WebhookSignature::verify($header, $body, $secret, $now, 300);

        $this->assertFalse($result);
    }
}
