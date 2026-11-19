<?php

namespace App\Application\Agents;

use App\Application\RateLimiting\DatabaseRateLimiter;
use App\Domain\Monitoring\Agent\AgentToken;
use App\Domain\Monitoring\Agent\LineProtocolParser;
use App\Domain\Monitoring\Agent\MetricSample;
use App\Domain\Monitoring\Agent\OtlpJsonMetricsParser;
use App\Domain\Monitoring\MonitorKind;
use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\Eloquent\AgentMetricRepository;
use App\Infrastructure\Persistence\Eloquent\Monitor;

/**
 * The cheap ingestion path (agent-ingestion addendum §4.4): rate limits, one
 * token lookup by hash, parse, one batched upsert and last_ingest_at. No
 * check_results row, no rule evaluation, no notification: the scheduler
 * evaluates agent monitors on its own tick.
 */
final class AgentIngestService
{
    public function __construct(
        private readonly DatabaseRateLimiter $rateLimiter,
        private readonly AgentMetricRepository $metrics,
        private readonly Clock $clock,
    ) {
    }

    public function ingestLineProtocol(string $token, string $body, string $ip): IngestOutcome
    {
        return $this->ingest($token, $ip, function () use ($body): array {
            $result = (new LineProtocolParser(
                maxLines: (int) config('agent.max_lines', 2000),
                maxKeyLength: (int) config('agent.metric_key_max_length', 255),
            ))->parse($body);

            return $result->ok()
                ? [$result->samples, $result->ignoredStringFields + $result->droppedLongKeys, null]
                : [[], 0, $result->error->message];
        });
    }

    /**
     * OTLP/HTTP JSON (addendum §4.1). Rejected points, including those over the
     * per-monitor cardinality cap, are reported back as partialSuccess.
     */
    public function ingestOtlp(string $token, string $body, string $ip): IngestOutcome
    {
        return $this->ingest($token, $ip, function () use ($body): array {
            $result = (new OtlpJsonMetricsParser(
                maxDataPoints: (int) config('agent.max_lines', 2000),
                maxKeyLength: (int) config('agent.metric_key_max_length', 255),
                maxAttributes: (int) config('agent.otlp_max_attributes', 8),
                resourceAttributes: (array) config('agent.otlp_resource_attributes', ['host.name', 'service.name']),
            ))->parse($body);

            return $result->ok() ? [$result->samples, $result->rejectedDataPoints, null] : [[], 0, $result->error];
        });
    }

    /**
     * Shared path: rate limits and token lookup run before the body is parsed.
     *
     * @param  callable(): array{0: list<MetricSample>, 1: int, 2: ?string}  $parse  samples, dropped count, error
     */
    private function ingest(string $token, string $ip, callable $parse): IngestOutcome
    {
        $limited = $this->rateLimit($token, $ip);
        if ($limited !== null) {
            return $limited;
        }

        $monitor = $this->monitorFor($token);
        if ($monitor === null) {
            return new IngestOutcome(IngestOutcome::NOT_FOUND);
        }

        [$samples, $dropped, $error] = $parse();
        if ($error !== null) {
            return new IngestOutcome(IngestOutcome::INVALID, $error);
        }

        $stored = $this->metrics->store($monitor, $samples, $this->clock->nowUtc());

        return new IngestOutcome(
            IngestOutcome::STORED,
            stored: $stored->stored,
            dropped: $stored->droppedOverCardinality + $dropped,
        );
    }

    private function rateLimit(string $token, string $ip): ?IngestOutcome
    {
        $retry = $this->rateLimiter->tryHit('ingest_ip_'.$ip, (int) config('agent.rate_per_ip_per_minute', 60), 60)
            ?? $this->rateLimiter->tryHit('ingest_token_'.AgentToken::hash($token), (int) config('agent.rate_per_token_per_minute', 12), 60);

        return $retry === null ? null : new IngestOutcome(IngestOutcome::RATE_LIMITED, retryAfter: $retry);
    }

    /**
     * The token is hashed before any lookup, so the database only ever sees a
     * fixed-length digest; a malformed token takes the same path to a miss.
     */
    private function monitorFor(string $token): ?Monitor
    {
        $hash = AgentToken::hash($token);
        if (! AgentToken::looksValid($token)) {
            $hash = AgentToken::hash('sca_invalid:'.$token);
        }

        return Monitor::query()
            ->where('agent_token_hash', $hash)
            ->where('kind', MonitorKind::AGENT)
            ->where('enabled', true)
            ->first();
    }
}
