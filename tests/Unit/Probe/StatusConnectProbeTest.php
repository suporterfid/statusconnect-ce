<?php

namespace Tests\Unit\Probe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../probe/src/Probe.php';

/**
 * Remote probe (#128): signature, address checks and result shape.
 * No network: the resolver, HTTP and TCP calls are fakes.
 */
class StatusConnectProbeTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef';
    private const NOW = 1_800_000_000;

    /** @var list<array<string, mixed>> */
    private array $httpCalls = [];

    /**
     * @param  array<string, list<string>>  $dns
     * @param  list<array<string, mixed>>  $responses
     */
    private function probe(array $dns = [], array $responses = [], ?array $tcp = null, string $secret = self::SECRET): \StatusConnectProbe
    {
        $this->httpCalls = [];

        return new \StatusConnectProbe(
            $secret,
            'São Paulo, BR',
            static fn (string $host): array => $dns[$host] ?? [],
            function (array $request) use (&$responses): array {
                $this->httpCalls[] = $request;

                return array_shift($responses) ?? ['status_code' => null, 'headers' => [], 'body' => '', 'tls_expires_at' => null, 'error' => 'no_fake'];
            },
            static fn (string $ip, int $port, int $timeout): array => $tcp ?? ['connected' => true, 'error' => null],
            static fn (): int => self::NOW,
        );
    }

    /** @return array{status:int, body:array<string, mixed>} */
    private function send(\StatusConnectProbe $probe, array $payload, ?int $timestamp = null, ?string $secret = null): array
    {
        $raw = json_encode($payload);
        $ts = (string) ($timestamp ?? self::NOW);

        return $probe->handle('POST', '/', [
            'X-StatusConnect-Timestamp' => $ts,
            'X-StatusConnect-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.$raw, $secret ?? self::SECRET),
        ], $raw);
    }

    private static function ok(int $status = 200, array $headers = [], string $body = 'hello'): array
    {
        return ['status_code' => $status, 'headers' => $headers, 'body' => $body, 'tls_expires_at' => '2027-01-01T00:00:00Z', 'error' => null];
    }

    private static function http(string $url, array $extra = []): array
    {
        return $extra + ['v' => 1, 'kind' => 'http', 'url' => $url, 'method' => 'GET', 'headers' => [], 'body' => null, 'timeout_ms' => 5000, 'verify_tls' => true, 'follow_redirects' => true];
    }

    public function test_valid_signature_returns_http_result_shape(): void
    {
        $probe = $this->probe(['example.com' => ['93.184.215.14']], [self::ok(200, ['content-type' => 'text/html'], 'hello world')]);

        $response = $this->send($probe, self::http('https://example.com/path?q=1', ['headers' => ['X-Test' => 'a', 'Host' => 'evil.test']]));

        $this->assertSame(200, $response['status']);
        $body = $response['body'];
        $this->assertSame(1, $body['v']);
        $this->assertSame('São Paulo, BR', $body['location']);
        $this->assertSame('http', $body['kind']);
        $this->assertTrue($body['ok']);
        $this->assertSame(200, $body['status_code']);
        $this->assertIsInt($body['latency_ms']);
        $this->assertSame(['content-type' => 'text/html'], (array) $body['headers']);
        $this->assertSame('hello world', base64_decode($body['body_base64']));
        $this->assertSame('2027-01-01T00:00:00Z', $body['tls_expires_at']);
        $this->assertNull($body['error']);

        $call = $this->httpCalls[0];
        $this->assertSame('93.184.215.14', $call['ip']);
        $this->assertSame('example.com', $call['host']);
        $this->assertSame(443, $call['port']);
        $this->assertSame(['X-Test' => 'a'], $call['headers'], 'Host header is dropped');
    }

    public function test_batch_runs_every_check_in_parallel_rounds_and_keeps_ids(): void
    {
        $rounds = [];
        $probe = new \StatusConnectProbe(
            self::SECRET,
            'São Paulo, BR',
            static fn (string $host): array => ['example.com' => ['93.184.215.14'], 'example.org' => ['93.184.215.15'], 'internal.example' => ['10.0.0.5']][$host] ?? [],
            now: static fn (): int => self::NOW,
            httpMulti: function (array $requests) use (&$rounds): array {
                $rounds[] = array_column($requests, 'url');

                return array_map(static fn (array $r): array => $r['url'] === 'https://example.org/old'
                    ? self::ok(301, ['location' => '/new'], '')
                    : self::ok(200, [], 'body of '.$r['url']), $requests);
            },
        );

        $response = $this->send($probe, ['v' => 1, 'kind' => 'batch', 'checks' => [
            self::http('https://example.com/a', ['id' => 'm-1']),
            self::http('https://example.org/old', ['id' => 2]),
            self::http('https://internal.example/', ['id' => 'm-3']),
            self::http('https://nowhere.example/', ['id' => 'm-4']),
        ]]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('São Paulo, BR', $response['body']['location']);
        $results = $response['body']['results'];
        $this->assertSame(['m-1', 2, 'm-3', 'm-4'], array_column($results, 'id'));
        $this->assertSame(200, $results[0]['status_code']);
        $this->assertSame('body of https://example.com/a', base64_decode($results[0]['body_base64']));
        $this->assertSame('body of https://example.org/new', base64_decode($results[1]['body_base64']), 'redirect followed');
        $this->assertSame(['id' => 'm-3', 'blocked' => 'private_address'], $results[2]);
        $this->assertSame('dns_resolution_failed', $results[3]['error']);
        // Round 1 runs both public checks together; round 2 only the redirect hop.
        $this->assertSame([['https://example.com/a', 'https://example.org/old'], ['https://example.org/new']], $rounds);
    }

    public function test_curl_multi_runs_requests_in_parallel_in_request_order(): void
    {
        $ip = gethostbyname('target');
        if ($ip === 'target') {
            $this->markTestSkipped('The target service is not reachable.');
        }
        $request = static fn (string $path): array => [
            'method' => 'GET', 'url' => 'http://target:8080'.$path, 'host' => 'target', 'port' => 8080, 'ip' => $ip,
            'headers' => [], 'body' => null, 'timeout_ms' => 5000, 'verify_tls' => true,
        ];
        $multi = (new \ReflectionMethod(\StatusConnectProbe::class, 'httpMultiViaCurl'))->getClosure();

        $started = hrtime(true);
        $results = $multi([$request('/delay/1000'), $request('/status/503'), $request('/delay/1000')]);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $this->assertSame([200, 503, 200], array_column($results, 'status_code'));
        // Each request reports its own time, not the batch's.
        $this->assertGreaterThanOrEqual(1_000, $results[0]['elapsed_ms']);
        $this->assertLessThan(500, $results[1]['elapsed_ms']);
        $this->assertSame('Status 503', $results[1]['body']);
        $this->assertLessThan(1_800, $elapsedMs, 'the two 1 s requests ran together');
    }

    public function test_every_curl_error_maps_to_a_code_without_a_fatal_error(): void
    {
        $map = (new \ReflectionMethod(\StatusConnectProbe::class, 'curlErrorCode'))->getClosure();

        $this->assertSame('connection_reset', $map(56));
        $this->assertSame('connection_reset', $map(55));
        $this->assertSame('tls_verification_failed', $map(60));
        $this->assertSame('timeout', $map(28));
        $this->assertSame('curl_error_99', $map(99));
    }

    public function test_batch_latency_is_each_check_own_time(): void
    {
        $probe = new \StatusConnectProbe(
            self::SECRET,
            'São Paulo, BR',
            static fn (string $host): array => ['93.184.215.14'],
            now: static fn (): int => self::NOW,
            httpMulti: static fn (array $requests): array => array_map(
                static fn (array $r): array => (str_contains($r['url'], 'slow') ? ['elapsed_ms' => 900] : ['elapsed_ms' => 40]) + self::ok(),
                $requests,
            ),
        );

        $results = $this->send($probe, ['v' => 1, 'kind' => 'batch', 'checks' => [
            self::http('https://example.com/slow', ['id' => 'a']),
            self::http('https://example.com/fast', ['id' => 'b']),
        ]])['body']['results'];

        $this->assertGreaterThanOrEqual(900, $results[0]['latency_ms']);
        $this->assertLessThan(900, $results[1]['latency_ms']);
    }

    public function test_invalid_batches_are_refused(): void
    {
        $probe = $this->probe(['example.com' => ['93.184.215.14']], [self::ok()]);
        $check = static fn (string $id): array => self::http('https://example.com/', ['id' => $id]);

        foreach ([
            'empty' => [],
            'too many' => array_map(static fn (int $i): array => $check((string) $i), range(1, 51)),
            'duplicate ids' => [$check('a'), $check('a')],
            'missing id' => [self::http('https://example.com/')],
            'tcp item' => [['id' => 'x', 'v' => 1, 'kind' => 'tcp', 'host' => 'example.com', 'port' => 443]],
            'not a list' => ['a' => $check('a')],
        ] as $label => $checks) {
            $this->assertSame(400, $this->send($probe, ['v' => 1, 'kind' => 'batch', 'checks' => $checks])['status'], $label);
        }
        $this->assertSame(401, $this->send($probe, ['v' => 1, 'kind' => 'batch', 'checks' => [$check('a')]], secret: str_repeat('x', 40))['status']);
    }

    public function test_body_is_capped_at_64_kib(): void
    {
        $probe = $this->probe(['example.com' => ['93.184.215.14']], [self::ok(200, [], str_repeat('a', 70000))]);

        $body = $this->send($probe, self::http('http://example.com/'))['body'];

        $this->assertSame(65536, strlen(base64_decode($body['body_base64'])));
    }

    public function test_transport_error_is_reported_with_ok_false(): void
    {
        $probe = $this->probe(['example.com' => ['93.184.215.14']], [['status_code' => null, 'headers' => [], 'body' => '', 'tls_expires_at' => null, 'error' => 'timeout']]);

        $body = $this->send($probe, self::http('https://example.com/'))['body'];

        $this->assertFalse($body['ok']);
        $this->assertNull($body['status_code']);
        $this->assertSame('timeout', $body['error']);
    }

    public function test_unresolvable_host_is_a_failed_check_not_an_error(): void
    {
        $body = $this->send($this->probe(), self::http('https://does-not-exist.example/'));

        $this->assertSame(200, $body['status']);
        $this->assertFalse($body['body']['ok']);
        $this->assertSame('dns_resolution_failed', $body['body']['error']);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $probe = $this->probe(['example.com' => ['93.184.215.14']], [self::ok()]);

        $response = $this->send($probe, self::http('https://example.com/'), secret: str_repeat('x', 40));

        $this->assertSame(401, $response['status']);
        $this->assertSame(['error' => 'unauthorized'], $response['body']);
        $this->assertSame([], $this->httpCalls);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $response = $this->probe()->handle('POST', '/', [], json_encode(self::http('https://example.com/')));

        $this->assertSame(401, $response['status']);
    }

    public function test_tampered_body_is_rejected(): void
    {
        $raw = json_encode(self::http('https://example.com/'));
        $ts = (string) self::NOW;
        $signature = 'sha256='.hash_hmac('sha256', $ts.'.'.$raw, self::SECRET);

        $response = $this->probe()->handle('POST', '/', [
            'x-statusconnect-timestamp' => $ts,
            'x-statusconnect-signature' => $signature,
        ], str_replace('example.com', 'example.org', $raw));

        $this->assertSame(401, $response['status']);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $probe = $this->probe(['example.com' => ['93.184.215.14']], [self::ok()]);

        $this->assertSame(401, $this->send($probe, self::http('https://example.com/'), self::NOW - 61)['status']);
        $this->assertSame(401, $this->send($probe, self::http('https://example.com/'), self::NOW + 61)['status']);
        $this->assertSame(200, $this->send($probe, self::http('https://example.com/'), self::NOW - 60)['status']);
    }

    public function test_missing_or_short_secret_fails_closed(): void
    {
        foreach (['', 'too-short-secret'] as $secret) {
            $probe = $this->probe(['example.com' => ['93.184.215.14']], [self::ok()], secret: $secret);

            $response = $this->send($probe, self::http('https://example.com/'), secret: $secret);

            $this->assertSame(503, $response['status']);
            $this->assertFalse($probe->verifySignature((string) self::NOW, 'sha256='.hash_hmac('sha256', self::NOW.'.x', $secret), 'x'));
        }
        $this->assertSame([], $this->httpCalls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPublicAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10' => ['10.1.2.3'],
            'private 172' => ['172.20.0.5'],
            'private 192' => ['192.168.1.1'],
            'metadata' => ['169.254.169.254'],
            'cgnat' => ['100.64.0.1'],
            'cgnat upper' => ['100.127.255.254'],
            'zero' => ['0.0.0.0'],
            'multicast' => ['224.0.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unspecified' => ['::'],
            'ipv6 ula' => ['fd00::1'],
            'ipv6 link local' => ['fe80::1'],
            'ipv4 mapped' => ['::ffff:127.0.0.1'],
            'ipv4 mapped public' => ['::ffff:8.8.8.8'],
            'nat64' => ['64:ff9b::a00:1'],
            'not an ip' => ['example.com'],
        ];
    }

    #[DataProvider('nonPublicAddresses')]
    public function test_non_public_addresses_are_not_public(string $ip): void
    {
        $this->assertFalse(\StatusConnectProbe::isPublicIp($ip));
    }

    public function test_public_addresses_are_allowed(): void
    {
        foreach (['8.8.8.8', '93.184.215.14', '100.128.0.1', '2606:4700:4700::1111', '[2001:4860:4860::8888]'] as $ip) {
            $this->assertTrue(\StatusConnectProbe::isPublicIp($ip), $ip);
        }
    }

    public function test_target_resolving_to_a_private_address_is_blocked(): void
    {
        $probe = $this->probe(['internal.example' => ['93.184.215.14', '10.0.0.5']], [self::ok()]);

        $response = $this->send($probe, self::http('https://internal.example/'));

        $this->assertSame(422, $response['status']);
        $this->assertSame(['error' => 'blocked:private_address'], $response['body']);
        $this->assertSame([], $this->httpCalls);
    }

    public function test_ip_literal_targets_are_checked(): void
    {
        $probe = $this->probe([], [self::ok()]);

        $this->assertSame(422, $this->send($probe, self::http('http://127.0.0.1/'))['status']);
        $this->assertSame(422, $this->send($probe, self::http('http://[::1]:8080/'))['status']);
        $this->assertSame(422, $this->send($probe, self::http('http://169.254.169.254/latest/meta-data/'))['status']);
        $this->assertSame([], $this->httpCalls);
    }

    public function test_redirects_are_followed_and_rechecked(): void
    {
        $probe = $this->probe(
            ['example.com' => ['93.184.215.14'], 'www.example.com' => ['93.184.215.15']],
            [self::ok(301, ['location' => 'https://www.example.com/home']), self::ok(302, ['location' => '/final']), self::ok(200)],
        );

        $body = $this->send($probe, self::http('http://example.com/'))['body'];

        $this->assertTrue($body['ok']);
        $this->assertSame(200, $body['status_code']);
        $this->assertSame(['http://example.com/', 'https://www.example.com/home', 'https://www.example.com/final'], array_column($this->httpCalls, 'url'));
        $this->assertSame('93.184.215.15', $this->httpCalls[2]['ip']);
    }

    public function test_redirect_to_a_private_address_is_blocked(): void
    {
        $probe = $this->probe(
            ['example.com' => ['93.184.215.14'], 'evil.example' => ['192.168.0.10']],
            [self::ok(302, ['location' => 'http://evil.example/admin']), self::ok()],
        );

        $response = $this->send($probe, self::http('https://example.com/'));

        $this->assertSame(422, $response['status']);
        $this->assertSame(['error' => 'blocked:private_address'], $response['body']);
        $this->assertCount(1, $this->httpCalls);
    }

    public function test_redirect_is_returned_when_not_following(): void
    {
        $probe = $this->probe(['example.com' => ['93.184.215.14']], [self::ok(301, ['location' => 'https://example.com/x'])]);

        $body = $this->send($probe, self::http('http://example.com/', ['follow_redirects' => false]))['body'];

        $this->assertSame(301, $body['status_code']);
        $this->assertCount(1, $this->httpCalls);
    }

    public function test_redirect_loop_stops(): void
    {
        $probe = $this->probe(['example.com' => ['93.184.215.14']], array_fill(0, 10, self::ok(302, ['location' => '/again'])));

        $body = $this->send($probe, self::http('https://example.com/'))['body'];

        $this->assertFalse($body['ok']);
        $this->assertSame('too_many_redirects', $body['error']);
        $this->assertCount(6, $this->httpCalls);
    }

    public function test_tcp_result(): void
    {
        $probe = $this->probe(['db.example.com' => ['93.184.215.14']]);
        $body = $this->send($probe, ['v' => 1, 'kind' => 'tcp', 'host' => 'db.example.com', 'port' => 5432, 'timeout_ms' => 3000])['body'];

        $this->assertSame('tcp', $body['kind']);
        $this->assertTrue($body['ok']);
        $this->assertNull($body['status_code']);
        $this->assertNull($body['error']);
        $this->assertSame('São Paulo, BR', $body['location']);

        $failing = $this->probe(['db.example.com' => ['93.184.215.14']], tcp: ['connected' => false, 'error' => 'timeout']);
        $body = $this->send($failing, ['v' => 1, 'kind' => 'tcp', 'host' => 'db.example.com', 'port' => 5432, 'timeout_ms' => 3000])['body'];
        $this->assertFalse($body['ok']);
        $this->assertSame('timeout', $body['error']);

        $blocked = $this->send($this->probe(), ['v' => 1, 'kind' => 'tcp', 'host' => '10.0.0.1', 'port' => 22, 'timeout_ms' => 3000]);
        $this->assertSame(422, $blocked['status']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidRequests(): array
    {
        return [
            'unknown kind' => [['v' => 1, 'kind' => 'icmp', 'host' => 'example.com']],
            'wrong version' => [['v' => 2, 'kind' => 'http', 'url' => 'https://example.com/']],
            'ftp url' => [self::http('ftp://example.com/')],
            'file url' => [self::http('file:///etc/passwd')],
            'no host' => [self::http('https:///path')],
            'userinfo' => [self::http('https://user:pass@example.com/')],
            'bad method' => [self::http('https://example.com/', ['method' => 'CONNECT'])],
            'header injection' => [self::http('https://example.com/', ['headers' => ['X-A' => "a\r\nX-B: b"]])],
            'bad header name' => [self::http('https://example.com/', ['headers' => ['X A' => 'b']])],
            'tcp bad port' => [['v' => 1, 'kind' => 'tcp', 'host' => 'example.com', 'port' => 70000]],
            'tcp no port' => [['v' => 1, 'kind' => 'tcp', 'host' => 'example.com']],
            'timeout not int' => [self::http('https://example.com/', ['timeout_ms' => '5000'])],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_requests_are_rejected(array $payload): void
    {
        $response = $this->send($this->probe(['example.com' => ['93.184.215.14']], [self::ok()]), $payload);

        $this->assertSame(400, $response['status']);
        $this->assertSame(['error' => 'invalid_request'], $response['body']);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $ts = (string) self::NOW;
        $raw = '{not json';
        $response = $this->probe()->handle('POST', '/', [
            'X-StatusConnect-Timestamp' => $ts,
            'X-StatusConnect-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.$raw, self::SECRET),
        ], $raw);

        $this->assertSame(400, $response['status']);
    }

    public function test_timeout_is_capped_at_30_seconds(): void
    {
        $probe = $this->probe(['example.com' => ['93.184.215.14']], [self::ok()]);

        $this->send($probe, self::http('https://example.com/', ['timeout_ms' => 120000]));

        $this->assertLessThanOrEqual(30000, $this->httpCalls[0]['timeout_ms']);
    }

    public function test_delay_endpoint_is_off_by_default_and_capped(): void
    {
        $this->assertSame(404, $this->probe()->handle('GET', '/delay/500', [], '')['status']);

        $slept = [];
        $probe = new \StatusConnectProbe(self::SECRET, 'São Paulo, BR', maxDelayMs: 1000, sleep: function (int $us) use (&$slept): void { $slept[] = $us; });

        $this->assertSame(['status' => 200, 'body' => ['ok' => true, 'delayed_ms' => 500]], $probe->handle('GET', '/delay/500', [], ''));
        $this->assertSame(1000, $probe->handle('GET', '/probe/delay/9000/', [], '')['body']['delayed_ms']);
        // A POST is a check request like any other: it needs a signature and never sleeps.
        $this->assertSame(401, $probe->handle('POST', '/delay/500', [], '')['status']);
        $this->assertSame([500_000, 1_000_000], $slept);
    }

    public function test_healthz_and_method_checks(): void
    {
        $probe = $this->probe();

        $this->assertSame(['status' => 200, 'body' => ['ok' => true]], $probe->handle('GET', '/healthz', [], ''));
        $this->assertSame(200, $probe->handle('GET', '/probe/healthz/', [], '')['status']);
        $this->assertSame(200, $probe->handle('GET', '/health', [], '')['status']);
        $this->assertSame(405, $probe->handle('GET', '/', [], '')['status']);
        $this->assertSame(405, $probe->handle('PUT', '/', [], '')['status']);
    }
}
