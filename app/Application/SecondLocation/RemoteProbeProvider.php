<?php

namespace App\Application\SecondLocation;

use App\Domain\Monitoring\CheckOutcome;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The StatusConnect probe (probe/ in this repository) on another host:
 * Cloud Run, a second shared-hosting plan, any PHP server. Requests are
 * signed with HMAC-SHA256 over "<timestamp>.<body>".
 */
final class RemoteProbeProvider implements SecondLocationProvider
{
    public function name(): string
    {
        return 'probe';
    }

    public function configured(): bool
    {
        return $this->url() !== '' && strlen($this->secret()) >= 32;
    }

    public function check(ConfirmationRequest $request, float $timeoutSeconds): ?ProviderResult
    {
        $payload = $request->kind === 'tcp'
            ? ['v' => 1, 'kind' => 'tcp', 'host' => $request->host, 'port' => $request->port, 'timeout_ms' => $request->timeoutMs]
            : [
                'v' => 1, 'kind' => 'http', 'url' => $request->url, 'method' => strtoupper($request->method),
                'headers' => (object) $request->headers, 'body' => $request->body, 'timeout_ms' => $request->timeoutMs,
                'verify_tls' => $request->verifyTls, 'follow_redirects' => $request->followRedirects,
            ];
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();

        try {
            $response = Http::withHeaders([
                'X-StatusConnect-Timestamp' => $timestamp,
                'X-StatusConnect-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->secret()),
                'User-Agent' => 'StatusConnect-SecondLocation/1',
            ])
                ->timeout(max(1, (int) floor($timeoutSeconds)))
                ->connectTimeout(min(5, max(1, (int) floor($timeoutSeconds))))
                ->withBody($body, 'application/json')
                ->post($this->url());
        } catch (\Throwable $e) {
            Log::warning('Second-location probe unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $data = $response->json();
        if (! $response->ok() || ! is_array($data) || ! array_key_exists('ok', $data)) {
            Log::warning('Second-location probe refused the check', ['status' => $response->status()]);

            return null;
        }

        $location = is_string($data['location'] ?? null) && $data['location'] !== '' ? $data['location'] : 'probe';
        $latency = (int) ($data['latency_ms'] ?? 0);
        if ($data['ok'] !== true) {
            return new ProviderResult('probe', $location, CheckOutcome::transportError((string) ($data['error'] ?? 'probe_error'), $latency));
        }

        $tls = null;
        if (is_string($data['tls_expires_at'] ?? null)) {
            try {
                $tls = new \DateTimeImmutable($data['tls_expires_at']);
            } catch (\Throwable) {
            }
        }
        $decoded = base64_decode((string) ($data['body_base64'] ?? ''), true);

        return new ProviderResult('probe', $location, new CheckOutcome(
            statusCode: (int) ($data['status_code'] ?? 0),
            latencyMs: $latency,
            headers: is_array($data['headers'] ?? null) ? array_map(static fn ($v) => is_array($v) ? array_map('strval', $v) : (string) $v, $data['headers']) : [],
            body: $decoded === false ? '' : $decoded,
            tlsExpiresAt: $tls,
        ));
    }

    private function url(): string
    {
        return trim((string) config('second_location.probe.url', ''));
    }

    private function secret(): string
    {
        return (string) config('second_location.probe.secret', '');
    }
}
