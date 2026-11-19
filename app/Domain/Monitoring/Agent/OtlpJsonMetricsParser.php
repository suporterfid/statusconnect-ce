<?php

namespace App\Domain\Monitoring\Agent;

/**
 * Parses an OTLP/HTTP JSON ExportMetricsServiceRequest (agent-ingestion
 * addendum §4.1, §5.4, §5.5) into numeric samples, without protobuf.
 *
 * - Gauge and Sum keep the latest data point per canonical key (by
 *   timeUnixNano); asInt may arrive as a JSON string and becomes a number.
 * - Histogram, ExponentialHistogram and Summary points are rejected and
 *   counted, as are points with no value, non-finite values, more attributes
 *   than allowed, or keys longer than allowed. The count feeds partialSuccess.
 * - Key: metric name plus the allowed resource attributes and the data point
 *   attributes, sorted, e.g. "system.memory.utilization{host.name=web1,state=used}".
 */
final class OtlpJsonMetricsParser
{
    private const UNSUPPORTED = ['histogram', 'exponentialHistogram', 'summary'];

    private const FLAG_NO_RECORDED_VALUE = 1;

    /**
     * @param  list<string>  $resourceAttributes  resource attributes copied into keys
     */
    public function __construct(
        private readonly int $maxDataPoints = 2000,
        private readonly int $maxKeyLength = 255,
        private readonly int $maxAttributes = 8,
        private readonly array $resourceAttributes = ['host.name', 'service.name'],
    ) {
    }

    public function parse(string $body): OtlpParseResult
    {
        try {
            $request = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return OtlpParseResult::failed('Body is not valid JSON.');
        }
        if (! is_array($request) || array_is_list($request) && $request !== []) {
            return OtlpParseResult::failed('Body is not an ExportMetricsServiceRequest object.');
        }

        /** @var array<string, array{0: MetricSample, 1: int}> $latest */
        $latest = [];
        $rejected = 0;
        $points = 0;

        foreach ($this->list($request['resourceMetrics'] ?? []) as $resourceMetrics) {
            $resourceTags = $this->resourceTags($resourceMetrics['resource']['attributes'] ?? []);

            foreach ($this->list($resourceMetrics['scopeMetrics'] ?? []) as $scopeMetrics) {
                foreach ($this->list($scopeMetrics['metrics'] ?? []) as $metric) {
                    $name = is_string($metric['name'] ?? null) ? $metric['name'] : '';

                    foreach (self::UNSUPPORTED as $type) {
                        if (isset($metric[$type])) {
                            $count = count($this->list($metric[$type]['dataPoints'] ?? []));
                            $points += $count;
                            $rejected += $count;
                        }
                    }

                    $dataPoints = $this->list($metric['gauge']['dataPoints'] ?? $metric['sum']['dataPoints'] ?? []);
                    foreach ($dataPoints as $point) {
                        if (++$points > $this->maxDataPoints) {
                            return OtlpParseResult::failed(sprintf('More than %d data points.', $this->maxDataPoints));
                        }

                        $sample = $name === '' ? null : $this->sample($name, $resourceTags, $point);
                        if ($sample === null) {
                            $rejected++;

                            continue;
                        }

                        $time = $this->nanos($point['timeUnixNano'] ?? null);
                        if (isset($latest[$sample->key])) {
                            // Superseded points of the same series are not errors: only the last one is kept.
                            if ($latest[$sample->key][1] > $time) {
                                continue;
                            }
                        }
                        $latest[$sample->key] = [$sample, $time];
                    }
                }
            }
        }

        if ($points > $this->maxDataPoints) {
            return OtlpParseResult::failed(sprintf('More than %d data points.', $this->maxDataPoints));
        }

        return new OtlpParseResult(array_values(array_map(static fn (array $entry): MetricSample => $entry[0], $latest)), $rejected);
    }

    /**
     * @param  array<string, string>  $resourceTags
     */
    private function sample(string $name, array $resourceTags, mixed $point): ?MetricSample
    {
        if (! is_array($point)) {
            return null;
        }
        if (((int) ($point['flags'] ?? 0) & self::FLAG_NO_RECORDED_VALUE) !== 0) {
            return null;
        }

        $value = $this->value($point);
        if ($value === null) {
            return null;
        }

        $pointTags = $this->attributes($point['attributes'] ?? []);
        if ($pointTags === null || count($pointTags) > $this->maxAttributes) {
            return null;
        }

        $tags = $pointTags + $resourceTags;
        $key = MetricSample::canonicalKey($name, '', $tags);
        if (strlen($key) > $this->maxKeyLength) {
            return null;
        }
        ksort($tags, SORT_STRING);

        $time = $this->nanos($point['timeUnixNano'] ?? null);

        return new MetricSample($key, $name, '', $tags, $value, $time === 0 ? null : $time);
    }

    private function value(array $point): ?float
    {
        if (array_key_exists('asDouble', $point)) {
            $raw = $point['asDouble'];
        } elseif (array_key_exists('asInt', $point)) {
            $raw = $point['asInt'];
            if (is_string($raw) && preg_match('/^-?\d+$/', $raw) !== 1) {
                return null;
            }
        } else {
            return null;
        }

        // proto3 JSON writes NaN and Infinity as strings; they never make a usable sample.
        if (! is_int($raw) && ! is_float($raw) && ! (is_string($raw) && is_numeric($raw))) {
            return null;
        }
        $value = (float) $raw;

        return is_finite($value) ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function resourceTags(mixed $attributes): array
    {
        $all = $this->attributes($attributes) ?? [];

        return array_intersect_key($all, array_flip($this->resourceAttributes));
    }

    /**
     * Scalar attributes as strings. Array, kvlist and bytes values are left out.
     * Returns null for a structurally broken attribute list.
     *
     * @return array<string, string>|null
     */
    private function attributes(mixed $attributes): ?array
    {
        if (! is_array($attributes)) {
            return null;
        }

        $tags = [];
        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || ! is_string($attribute['key'] ?? null) || $attribute['key'] === '') {
                return null;
            }
            $value = $attribute['value'] ?? [];
            $string = match (true) {
                ! is_array($value) => null,
                isset($value['stringValue']) && is_string($value['stringValue']) => $value['stringValue'],
                isset($value['intValue']) && is_scalar($value['intValue']) => (string) $value['intValue'],
                isset($value['doubleValue']) && is_scalar($value['doubleValue']) => (string) $value['doubleValue'],
                isset($value['boolValue']) && is_bool($value['boolValue']) => $value['boolValue'] ? 'true' : 'false',
                default => null,
            };
            if ($string !== null) {
                $tags[$attribute['key']] = $string;
            }
        }

        return $tags;
    }

    private function nanos(mixed $raw): int
    {
        return (is_int($raw) || (is_string($raw) && ctype_digit($raw))) ? (int) $raw : 0;
    }

    /**
     * @return list<array<mixed>>
     */
    private function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
