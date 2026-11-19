<?php

declare(strict_types=1);

/**
 * StatusConnect remote probe (#128).
 *
 * A small, stateless endpoint that runs one HTTP or TCP check on behalf of a
 * StatusConnect checker and returns the raw result. The checker uses it to
 * confirm a failure from a second location before it opens an incident.
 *
 * Requests are signed with HMAC-SHA256 over "<timestamp>.<raw body>" using a
 * shared secret. Because the endpoint makes outbound requests on demand, it
 * only talks to public addresses: every host is resolved, every resolved IP
 * is checked, and the connection is pinned to a checked IP. Redirects are
 * followed by hand so that each hop is checked too.
 *
 * No framework and no Composer dependencies: PHP 8.2+ with ext-curl.
 */
final class StatusConnectProbe
{
    public const VERSION = 1;
    public const MAX_SKEW_SECONDS = 60;
    public const MIN_SECRET_LENGTH = 32;
    public const MAX_TIMEOUT_MS = 30000;
    public const DEFAULT_TIMEOUT_MS = 10000;
    public const MAX_BODY_BYTES = 65536;
    public const MAX_REQUEST_BYTES = 262144;
    public const MAX_REDIRECTS = 5;
    /** Checks per batch request (#222). */
    public const MAX_BATCH = 50;

    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private const DROPPED_HEADERS = ['host', 'content-length', 'connection', 'transfer-encoding'];
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];

    /**
     * Ranges refused on top of PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE.
     */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    private \Closure $resolver;
    private \Closure $http;
    private \Closure $tcp;
    private \Closure $now;
    private \Closure $sleep;
    private \Closure $httpMulti;

    /**
     * @param  (\Closure(string): list<string>)|null  $resolver  host name to IP addresses
     * @param  (\Closure(array): array)|null  $http  one HTTP request, no redirects (see httpViaCurl)
     * @param  (\Closure(string, int, int): array)|null  $tcp  ip, port, timeout ms => {connected, error}
     * @param  (\Closure(): int)|null  $now  unix seconds
     * @param  int  $maxDelayMs  enables GET /delay/{ms} up to this many ms (capacity tests, #214); 0 = off
     * @param  (\Closure(int): void)|null  $sleep  microseconds
     * @param  (\Closure(list<array>): list<array>)|null  $httpMulti  several HTTP requests in parallel, same shapes as $http
     */
    public function __construct(
        private readonly string $secret,
        private readonly string $location = 'unknown',
        ?\Closure $resolver = null,
        ?\Closure $http = null,
        ?\Closure $tcp = null,
        ?\Closure $now = null,
        private readonly int $maxDelayMs = 0,
        ?\Closure $sleep = null,
        ?\Closure $httpMulti = null,
    ) {
        $this->resolver = $resolver ?? self::resolveViaDns(...);
        $this->http = $http ?? self::httpViaCurl(...);
        $this->tcp = $tcp ?? self::tcpViaStream(...);
        $this->now = $now ?? static fn (): int => time();
        $this->sleep = $sleep ?? static function (int $us): void { usleep($us); };
        // An injected single-request $http (tests) also serves batches, one by one.
        $this->httpMulti = $httpMulti ?? ($http !== null
            ? fn (array $requests): array => array_map(fn (array $r): array => ($this->http)($r), $requests)
            : self::httpMultiViaCurl(...));
    }

    /**
     * @param  array<string, string>  $headers  request headers, any case
     * @return array{status:int, body:array<string, mixed>}
     */
    public function handle(string $method, string $path, array $headers, string $rawBody): array
    {
        $method = strtoupper($method);
        $path = rtrim($path, '/');

        // /health and /healthz: Cloud Run reserves paths ending in "z", so /health is the one that works there.
        if ($method === 'GET' && preg_match('#(^|/)healthz?$#', $path) === 1) {
            return ['status' => 200, 'body' => ['ok' => true]];
        }
        // A slow target for the checker's capacity test (#214): answers after {ms}
        // milliseconds. Off unless PROBE_DELAY_MAX_MS is set; capped at that value.
        if ($method === 'GET' && preg_match('#(^|/)delay/(\d{1,5})$#', $path, $m) === 1) {
            if ($this->maxDelayMs <= 0) {
                return ['status' => 404, 'body' => ['error' => 'not_found']];
            }
            $ms = min((int) $m[2], $this->maxDelayMs);
            ($this->sleep)($ms * 1000);

            return ['status' => 200, 'body' => ['ok' => true, 'delayed_ms' => $ms]];
        }
        if ($method !== 'POST') {
            return ['status' => 405, 'body' => ['error' => 'method_not_allowed']];
        }
        if (strlen($this->secret) < self::MIN_SECRET_LENGTH) {
            return ['status' => 503, 'body' => ['error' => 'not_configured']];
        }

        $lower = array_change_key_case($headers, CASE_LOWER);
        if (! $this->verifySignature(
            (string) ($lower['x-statusconnect-timestamp'] ?? ''),
            (string) ($lower['x-statusconnect-signature'] ?? ''),
            $rawBody,
        )) {
            return ['status' => 401, 'body' => ['error' => 'unauthorized']];
        }

        $request = $this->validateRequest($rawBody);
        if ($request === null) {
            return ['status' => 400, 'body' => ['error' => 'invalid_request']];
        }

        if ($request['kind'] === 'batch') {
            return ['status' => 200, 'body' => $this->runBatch($request['checks'])];
        }
        $result = $request['kind'] === 'tcp' ? $this->runTcp($request) : $this->runHttp($request);
        if (isset($result['blocked'])) {
            return ['status' => 422, 'body' => ['error' => 'blocked:'.$result['blocked']]];
        }

        return ['status' => 200, 'body' => $result];
    }

    public function verifySignature(string $timestamp, string $signature, string $rawBody): bool
    {
        if (strlen($this->secret) < self::MIN_SECRET_LENGTH) {
            return false;
        }
        if ($timestamp === '' || ! ctype_digit($timestamp) || strlen($timestamp) > 12) {
            return false;
        }
        if (abs(($this->now)() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }
        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @return array<string, mixed>|null normalised request, or null when invalid
     */
    public function validateRequest(string $rawBody): ?array
    {
        if ($rawBody === '' || strlen($rawBody) > self::MAX_REQUEST_BYTES) {
            return null;
        }
        try {
            $data = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($data) || ($data['v'] ?? null) !== self::VERSION) {
            return null;
        }

        if (($data['kind'] ?? null) === 'batch') {
            return $this->validateBatch($data);
        }

        return $this->validateCheck($data);
    }

    /**
     * A batch (#222): up to MAX_BATCH HTTP checks, each with a caller-chosen id.
     *
     * @param  array<string, mixed>  $data
     * @return array{kind:'batch', checks:list<array<string, mixed>>}|null
     */
    private function validateBatch(array $data): ?array
    {
        $checks = $data['checks'] ?? null;
        if (! is_array($checks) || ! array_is_list($checks) || $checks === [] || count($checks) > self::MAX_BATCH) {
            return null;
        }
        $normalised = [];
        $ids = [];
        foreach ($checks as $item) {
            if (! is_array($item)) {
                return null;
            }
            $id = $item['id'] ?? null;
            if ((! is_string($id) && ! is_int($id)) || strlen((string) $id) > 64 || isset($ids[(string) $id])) {
                return null;
            }
            $ids[(string) $id] = true;
            $check = $this->validateCheck($item);
            if ($check === null || $check['kind'] !== 'http') {
                return null;
            }
            $normalised[] = ['id' => $id] + $check;
        }

        return ['kind' => 'batch', 'checks' => $normalised];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function validateCheck(array $data): ?array
    {
        $timeout = $data['timeout_ms'] ?? self::DEFAULT_TIMEOUT_MS;
        if (! is_int($timeout) || $timeout < 1) {
            return null;
        }
        $timeout = min($timeout, self::MAX_TIMEOUT_MS);

        $kind = $data['kind'] ?? null;
        if ($kind === 'tcp') {
            $host = $data['host'] ?? null;
            $port = $data['port'] ?? null;
            if (! is_string($host) || ! self::isValidHost($host) || ! is_int($port) || $port < 1 || $port > 65535) {
                return null;
            }

            return ['kind' => 'tcp', 'host' => self::stripBrackets($host), 'port' => $port, 'timeout_ms' => $timeout];
        }
        if ($kind !== 'http') {
            return null;
        }

        $url = $data['url'] ?? null;
        if (! is_string($url) || self::parseTarget($url) === null) {
            return null;
        }
        $method = strtoupper((string) ($data['method'] ?? 'GET'));
        if (! in_array($method, self::METHODS, true)) {
            return null;
        }
        $body = $data['body'] ?? null;
        if ($body !== null && ! is_string($body)) {
            return null;
        }
        $headers = [];
        $rawHeaders = $data['headers'] ?? [];
        if (! is_array($rawHeaders)) {
            return null;
        }
        foreach ($rawHeaders as $name => $value) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
                return null;
            }
            if (! is_string($value) && ! is_int($value)) {
                return null;
            }
            $value = (string) $value;
            if (preg_match('/[\r\n\0]/', $value)) {
                return null;
            }
            if (in_array(strtolower($name), self::DROPPED_HEADERS, true)) {
                continue;
            }
            $headers[$name] = $value;
        }
        foreach (['verify_tls', 'follow_redirects'] as $flag) {
            if (isset($data[$flag]) && ! is_bool($data[$flag])) {
                return null;
            }
        }

        return [
            'kind' => 'http',
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout_ms' => $timeout,
            'verify_tls' => $data['verify_tls'] ?? true,
            'follow_redirects' => $data['follow_redirects'] ?? true,
        ];
    }

    public static function isPublicIp(string $ip): bool
    {
        $ip = self::stripBrackets($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is refused outright.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return false;
        }
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function runHttp(array $request): array
    {
        return $this->runHttpMany([$request])[0];
    }

    /**
     * A batch (#222): every check runs in parallel and keeps its id.
     *
     * @param  list<array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    private function runBatch(array $checks): array
    {
        $results = [];
        foreach ($this->runHttpMany($checks) as $i => $result) {
            $results[] = isset($result['blocked'])
                ? ['id' => $checks[$i]['id'], 'blocked' => $result['blocked']]
                : ['id' => $checks[$i]['id']] + $result;
        }

        return ['v' => self::VERSION, 'location' => $this->location, 'results' => $results];
    }

    /**
     * Runs HTTP checks together. Each round sends the current hop of every
     * unfinished check in parallel; a redirect is resolved, checked and pinned
     * again before the next round, as for a single check.
     *
     * @param  list<array<string, mixed>>  $requests
     * @return list<array<string, mixed>> one result per request, same order
     */
    private function runHttpMany(array $requests): array
    {
        $now = hrtime(true);
        $states = [];
        foreach ($requests as $i => $request) {
            $states[$i] = [
                'request' => $request,
                'started' => $now,
                'deadline' => $now + $request['timeout_ms'] * 1_000_000,
                'url' => $request['url'],
                'method' => $request['method'],
                'body' => $request['body'],
                'hop' => 0,
                // Time spent on this check alone: DNS plus each hop's transfer.
                // Wall-clock time would be the whole round's for every check.
                'elapsed_ms' => 0,
                'timed' => true,
            ];
        }
        $results = [];

        while ($states !== []) {
            $hops = [];
            foreach ($states as $i => $state) {
                $target = self::parseTarget($state['url']);
                if ($target === null) {
                    $results[$i] = $this->httpResult(false, null, $state['started'], [], '', null, 'invalid_redirect');
                    unset($states[$i]);
                    continue;
                }
                $dnsStarted = hrtime(true);
                $ip = $this->pickIp($target['host']);
                $states[$i]['elapsed_ms'] += (int) round((hrtime(true) - $dnsStarted) / 1_000_000);
                if (is_array($ip)) {
                    $results[$i] = $ip['blocked'] === null
                        ? $this->httpResult(false, null, $state['started'], [], '', null, 'dns_resolution_failed')
                        : ['blocked' => $ip['blocked']];
                    unset($states[$i]);
                    continue;
                }
                $remainingMs = (int) floor(($state['deadline'] - hrtime(true)) / 1_000_000);
                if ($remainingMs <= 0) {
                    $results[$i] = $this->httpResult(false, null, $state['started'], [], '', null, 'timeout');
                    unset($states[$i]);
                    continue;
                }
                $hops[$i] = [
                    'method' => $state['method'],
                    'url' => $state['url'],
                    'host' => $target['host'],
                    'port' => $target['port'],
                    'ip' => $ip,
                    'headers' => $state['request']['headers'],
                    'body' => $state['body'],
                    'timeout_ms' => $remainingMs,
                    'verify_tls' => $state['request']['verify_tls'],
                ];
            }
            if ($hops === []) {
                break;
            }

            $responses = ($this->httpMulti)(array_values($hops));
            foreach (array_keys($hops) as $n => $i) {
                $response = $responses[$n] ?? ['status_code' => null, 'headers' => [], 'body' => '', 'tls_expires_at' => null, 'error' => 'no_response'];
                if (is_int($response['elapsed_ms'] ?? null)) {
                    $states[$i]['elapsed_ms'] += $response['elapsed_ms'];
                } else {
                    $states[$i]['timed'] = false;
                }
                $state = $states[$i];
                $latencyMs = $state['timed'] ? $state['elapsed_ms'] : null;
                $status = $response['status_code'] ?? null;
                $location = $response['headers']['location'] ?? null;
                $redirect = $state['request']['follow_redirects'] && in_array($status, self::REDIRECT_CODES, true) && is_string($location) && $location !== '';

                if ($redirect && $state['hop'] === self::MAX_REDIRECTS) {
                    $results[$i] = $this->httpResult(false, $status, $state['started'], $response['headers'] ?? [], '', null, 'too_many_redirects', $latencyMs);
                    unset($states[$i]);
                    continue;
                }
                if ($redirect) {
                    $states[$i]['url'] = self::resolveLocation($state['url'], $location);
                    if ($status === 303 || (in_array($status, [301, 302], true) && $state['method'] === 'POST')) {
                        $states[$i]['method'] = 'GET';
                        $states[$i]['body'] = null;
                    }
                    $states[$i]['hop']++;
                    continue;
                }

                $error = $response['error'] ?? null;
                $ok = $error === null && is_int($response['status_code'] ?? null) && $response['status_code'] > 0;
                $results[$i] = $this->httpResult(
                    $ok,
                    $ok ? $response['status_code'] : null,
                    $state['started'],
                    $response['headers'] ?? [],
                    (string) ($response['body'] ?? ''),
                    $response['tls_expires_at'] ?? null,
                    $error,
                    $latencyMs,
                );
                unset($states[$i]);
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function httpResult(bool $ok, ?int $status, int $started, array $headers, string $body, ?string $tlsExpiresAt, ?string $error, ?int $latencyMs = null): array
    {
        return [
            'v' => self::VERSION,
            'location' => $this->location,
            'kind' => 'http',
            'ok' => $ok,
            'status_code' => $status,
            'latency_ms' => $latencyMs ?? (int) round((hrtime(true) - $started) / 1_000_000),
            'headers' => (object) $headers,
            'body_base64' => base64_encode(substr($body, 0, self::MAX_BODY_BYTES)),
            'tls_expires_at' => $tlsExpiresAt,
            'error' => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function runTcp(array $request): array
    {
        $started = hrtime(true);
        $ip = $this->pickIp($request['host']);
        if (is_array($ip) && $ip['blocked'] !== null) {
            return ['blocked' => $ip['blocked']];
        }
        $outcome = is_array($ip)
            ? ['connected' => false, 'error' => 'dns_resolution_failed']
            : ($this->tcp)($ip, $request['port'], $request['timeout_ms']);
        $connected = (bool) ($outcome['connected'] ?? false);

        return [
            'v' => self::VERSION,
            'location' => $this->location,
            'kind' => 'tcp',
            'ok' => $connected,
            'status_code' => null,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'headers' => (object) [],
            'body_base64' => '',
            'tls_expires_at' => null,
            'error' => $connected ? null : (string) ($outcome['error'] ?? 'tcp_connect_error'),
        ];
    }

    /**
     * @return string|array{blocked:?string} the IP to connect to, or why not
     */
    private function pickIp(string $host): string|array
    {
        $host = self::stripBrackets($host);
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        $ips = array_values(array_unique(array_filter($ips, static fn ($ip) => is_string($ip) && $ip !== '')));
        if ($ips === []) {
            return ['blocked' => null];
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return ['blocked' => 'private_address'];
            }
        }
        // Prefer IPv4: many serverless egress paths have no IPv6 route.
        usort($ips, static fn (string $a, string $b): int => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        return $ips[0];
    }

    /**
     * @return array{scheme:string, host:string, port:int}|null
     */
    private static function parseTarget(string $url): ?array
    {
        if (strlen($url) > 2048 || preg_match('/[\s\0]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = self::stripBrackets(strtolower($parts['host']));
        if (! self::isValidHost($host)) {
            return null;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => (int) $port];
    }

    private static function isValidHost(string $host): bool
    {
        $host = self::stripBrackets($host);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return strlen($host) <= 253
            && str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private static function stripBrackets(string $host): string
    {
        return str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'http';
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }
        $authority = $scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $authority.$location;
        }
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        if (str_starts_with($location, '?')) {
            return $authority.$path.$location;
        }

        return $authority.($dir === '' ? '/' : $dir).$location;
    }

    private static function inCidr(string $packedIp, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $packedNet = inet_pton($network);
        if ($packedNet === false || strlen($packedNet) !== strlen($packedIp)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($packedIp, $packedNet, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packedIp[$bytes]) & $mask) === (ord($packedNet[$bytes]) & $mask);
    }

    /**
     * @return list<string>
     */
    private static function resolveViaDns(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }

        return $ips;
    }

    /**
     * One HTTP request with curl, pinned to an already-checked IP, without
     * following redirects.
     *
     * @param  array{method:string, url:string, host:string, port:int, ip:string, headers:array<string,string>, body:?string, timeout_ms:int, verify_tls:bool}  $r
     * @return array{status_code:?int, headers:array<string,string>, body:string, tls_expires_at:?string, error:?string}
     */
    private static function httpViaCurl(array $r): array
    {
        [$ch, $state] = self::curlHandle($r);
        curl_exec($ch);

        return self::curlResult($ch, $state, curl_errno($ch));
    }

    /**
     * Several HTTP requests in parallel with curl_multi (#222). Same request
     * and result shapes as httpViaCurl, results in request order.
     *
     * @param  list<array{method:string, url:string, host:string, port:int, ip:string, headers:array<string,string>, body:?string, timeout_ms:int, verify_tls:bool}>  $requests
     * @return list<array{status_code:?int, headers:array<string,string>, body:string, tls_expires_at:?string, error:?string}>
     */
    private static function httpMultiViaCurl(array $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $i => $r) {
            [$ch, $state] = self::curlHandle($r);
            curl_multi_add_handle($multi, $ch);
            $handles[spl_object_id($ch)] = [$i, $ch, $state];
        }
        $results = [];
        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
            while (($done = curl_multi_info_read($multi)) !== false) {
                [$i, $ch, $state] = $handles[spl_object_id($done['handle'])];
                $results[$i] = self::curlResult($ch, $state, (int) $done['result']);
                curl_multi_remove_handle($multi, $ch);
            }
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0);
        curl_multi_close($multi);
        ksort($results);

        return array_values($results);
    }

    /**
     * @param  array{method:string, url:string, host:string, port:int, ip:string, headers:array<string,string>, body:?string, timeout_ms:int, verify_tls:bool}  $r
     * @return array{0: \CurlHandle, 1: \stdClass}
     */
    private static function curlHandle(array $r): array
    {
        $ch = curl_init();
        $ip = str_contains($r['ip'], ':') ? '['.$r['ip'].']' : $r['ip'];
        $state = new \stdClass();
        $state->headers = [];
        $state->body = '';
        $state->capped = false;
        $requestHeaders = [];
        foreach ($r['headers'] as $name => $value) {
            $requestHeaders[] = $name.': '.$value;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $r['url'],
            CURLOPT_CUSTOMREQUEST => $r['method'],
            CURLOPT_NOBODY => $r['method'] === 'HEAD',
            CURLOPT_RESOLVE => [$r['host'].':'.$r['port'].':'.$ip],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_TIMEOUT_MS => $r['timeout_ms'],
            CURLOPT_CONNECTTIMEOUT_MS => min($r['timeout_ms'], 10000),
            CURLOPT_SSL_VERIFYPEER => $r['verify_tls'],
            CURLOPT_SSL_VERIFYHOST => $r['verify_tls'] ? 2 : 0,
            CURLOPT_CERTINFO => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_USERAGENT => 'StatusConnect-Probe/1',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use ($state): int {
                if (preg_match('#^HTTP/\S+\s+\d{3}#', $line)) {
                    $state->headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    $state->headers[$name] = isset($state->headers[$name]) ? $state->headers[$name].', '.$value : $value;
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($state): int {
                $room = StatusConnectProbe::MAX_BODY_BYTES - strlen($state->body);
                if ($room <= 0) {
                    $state->capped = true;

                    return 0; // abort: we have all we keep
                }
                $state->body .= substr($chunk, 0, $room);

                return strlen($chunk);
            },
        ]);
        if ($r['body'] !== null && $r['method'] !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $r['body']);
        }

        return [$ch, $state];
    }

    /**
     * @return array{status_code:?int, headers:array<string,string>, body:string, tls_expires_at:?string, error:?string}
     */
    private static function curlResult(\CurlHandle $ch, \stdClass $state, int $errno): array
    {
        $error = $errno !== 0 && ! ($state->capped && $errno === CURLE_WRITE_ERROR) ? self::curlErrorCode($errno) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $tlsExpiresAt = null;
        $certs = curl_getinfo($ch, CURLINFO_CERTINFO);
        if (is_array($certs) && isset($certs[0]['Expire date'])) {
            $ts = strtotime((string) $certs[0]['Expire date']);
            if ($ts !== false) {
                $tlsExpiresAt = gmdate('Y-m-d\TH:i:s\Z', $ts);
            }
        }
        $elapsedMs = (int) round(((int) curl_getinfo($ch, CURLINFO_TOTAL_TIME_T)) / 1000);
        curl_close($ch);

        return [
            'status_code' => $status > 0 ? $status : null,
            'headers' => $state->headers,
            'body' => $state->body,
            'tls_expires_at' => $tlsExpiresAt,
            'error' => $error,
            'elapsed_ms' => $elapsedMs,
        ];
    }

    private static function curlErrorCode(int $errno): string
    {
        // libcurl error numbers, not ext-curl constants: some PHP builds lack
        // CURLE_PEER_FAILED_VERIFICATION, and an undefined constant in a match
        // arm is a fatal error that took the whole answer (or batch) down.
        return match ($errno) {
            28 => 'timeout',                    // CURLE_OPERATION_TIMEDOUT
            7 => 'connection_refused',          // CURLE_COULDNT_CONNECT
            6 => 'dns_resolution_failed',       // CURLE_COULDNT_RESOLVE_HOST
            35 => 'tls_handshake_failed',       // CURLE_SSL_CONNECT_ERROR
            60 => 'tls_verification_failed',    // CURLE_PEER_FAILED_VERIFICATION
            52 => 'empty_response',             // CURLE_GOT_NOTHING
            55, 56 => 'connection_reset',       // CURLE_SEND_ERROR, CURLE_RECV_ERROR
            default => 'curl_error_'.$errno,
        };
    }

    /**
     * @return array{connected:bool, error:?string}
     */
    private static function tcpViaStream(string $ip, int $port, int $timeoutMs): array
    {
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('tcp://'.$address.':'.$port, $errno, $errstr, $timeoutMs / 1000, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            return ['connected' => false, 'error' => $errno === 110 || $errno === 0 ? 'timeout' : 'tcp_connect_error'];
        }
        fclose($socket);

        return ['connected' => true, 'error' => null];
    }
}
