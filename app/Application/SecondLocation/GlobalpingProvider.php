<?php

namespace App\Application\SecondLocation;

use App\Domain\Monitoring\CheckOutcome;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Globalping (https://globalping.io, by jsDelivr): one measurement from one
 * probe at the configured location, read back by polling.
 *
 * Globalping measurement data is public to anyone holding the measurement
 * id, and its HTTP test only does GET/HEAD/OPTIONS with no way to skip TLS
 * verification. So monitors with custom headers, a body, another method or
 * TLS verification turned off are never sent here.
 */
final class GlobalpingProvider implements SecondLocationProvider
{
    /** @param  (\Closure(int): void)|null  $sleep  microseconds; replaced in tests */
    public function __construct(private readonly ?\Closure $sleep = null)
    {
    }

    public function name(): string
    {
        return 'globalping';
    }

    public function configured(): bool
    {
        return $this->apiUrl() !== '';
    }

    public function check(ConfirmationRequest $request, float $timeoutSeconds): ?ProviderResult
    {
        $measurement = $this->measurement($request);
        if ($measurement === null) {
            return null;
        }
        $deadline = microtime(true) + $timeoutSeconds;

        try {
            $created = $this->client($timeoutSeconds)->post($this->apiUrl().'/measurements', $measurement);
            $id = $created->json('id');
            if ($created->status() !== 202 || ! is_string($id) || $id === '') {
                Log::warning('Globalping refused the measurement', ['status' => $created->status(), 'error' => $created->json('error.type')]);

                return null;
            }

            while (true) {
                $this->sleep(500_000);
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    return null;
                }
                $read = $this->client($remaining)->get($this->apiUrl().'/measurements/'.rawurlencode($id));
                if ($read->status() === 429) {
                    continue;
                }
                if (! $read->ok()) {
                    return null;
                }
                if ($read->json('status') !== 'in-progress') {
                    return $this->result($request, (array) ($read->json('results.0') ?? []));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Globalping unreachable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function measurement(ConfirmationRequest $request): ?array
    {
        $location = [['magic' => (string) config('second_location.globalping.location', 'BR')]];

        if ($request->kind === 'tcp') {
            if ($request->host === '' || $request->port < 1) {
                return null;
            }

            return [
                'type' => 'ping', 'target' => $request->host, 'locations' => $location, 'limit' => 1,
                'measurementOptions' => ['protocol' => 'TCP', 'port' => $request->port, 'packets' => 3],
            ];
        }

        if ($request->hasCustomRequest() || ! $request->verifyTls) {
            return null;
        }
        $parts = parse_url($request->url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $options = [
            'protocol' => $scheme === 'https' ? 'HTTPS' : 'HTTP',
            'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'request' => array_filter([
                'method' => strtoupper($request->method),
                'path' => (string) ($parts['path'] ?? '/'),
                'query' => $parts['query'] ?? null,
            ], static fn ($v) => $v !== null),
        ];

        return ['type' => 'http', 'target' => trim($host, '[]'), 'locations' => $location, 'limit' => 1, 'measurementOptions' => $options];
    }

    /** @param  array<string, mixed>  $entry */
    private function result(ConfirmationRequest $request, array $entry): ?ProviderResult
    {
        $result = (array) ($entry['result'] ?? []);
        $status = $result['status'] ?? null;
        if (! in_array($status, ['finished', 'failed'], true)) {
            return null; // offline probe or no result: no verdict
        }
        $probe = (array) ($entry['probe'] ?? []);
        $location = trim(implode(', ', array_filter([(string) ($probe['city'] ?? ''), (string) ($probe['country'] ?? '')])), ', ') ?: 'Globalping';

        if ($request->kind === 'tcp') {
            $stats = (array) ($result['stats'] ?? []);
            $loss = is_numeric($stats['loss'] ?? null) ? (float) $stats['loss'] : 100.0;

            return new ProviderResult('globalping', $location, $status === 'finished' && $loss < 100
                ? new CheckOutcome(statusCode: 200, latencyMs: (int) round((float) ($stats['avg'] ?? 0)))
                : CheckOutcome::transportError('tcp_connect_error'));
        }

        if ($status === 'failed' || ! is_numeric($result['statusCode'] ?? null)) {
            $line = strtok((string) ($result['rawOutput'] ?? ''), "\n");

            return new ProviderResult('globalping', $location, CheckOutcome::transportError(
                'globalping: '.mb_substr($line === false || $line === '' ? 'request failed' : $line, 0, 200),
                (int) ($result['timings']['total'] ?? 0),
            ));
        }

        $tls = null;
        if (is_string($result['tls']['expiresAt'] ?? null)) {
            try {
                $tls = new \DateTimeImmutable($result['tls']['expiresAt']);
            } catch (\Throwable) {
            }
        }
        $headers = [];
        foreach ((array) ($result['headers'] ?? []) as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? array_map('strval', $value) : (string) $value;
        }

        return new ProviderResult('globalping', $location, new CheckOutcome(
            statusCode: (int) $result['statusCode'],
            latencyMs: (int) ($result['timings']['total'] ?? 0),
            headers: $headers,
            body: (string) ($result['rawBody'] ?? ''),
            tlsExpiresAt: $tls,
        ));
    }

    private function client(float $timeoutSeconds): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'StatusConnect-SecondLocation/1 (+'.rtrim((string) config('app.url'), '/').'/)'])
            ->timeout(max(1, (int) ceil($timeoutSeconds)))
            ->connectTimeout(min(5, max(1, (int) ceil($timeoutSeconds))));
        $token = (string) config('second_location.globalping.token', '');

        return $token === '' ? $client : $client->withToken($token);
    }

    private function sleep(int $microseconds): void
    {
        $this->sleep !== null ? ($this->sleep)($microseconds) : usleep($microseconds);
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('second_location.globalping.api_url', ''), '/');
    }
}
