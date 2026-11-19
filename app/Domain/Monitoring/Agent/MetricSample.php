<?php

namespace App\Domain\Monitoring\Agent;

/**
 * One numeric metric value from an agent, keyed canonically as
 * "measurement.field{tag=value,...}" (line protocol) or "name{attr=value,...}"
 * (OTLP), with tags sorted by key.
 */
final readonly class MetricSample
{
    /**
     * @param  array<string, string>  $tags  sorted by key
     */
    public function __construct(
        public string $key,
        public string $measurement,
        public string $field,
        public array $tags,
        public float $value,
        public ?int $timestampNs = null,
    ) {
    }

    /**
     * @param  array<string, string>  $tags
     */
    public static function canonicalKey(string $measurement, string $field, array $tags): string
    {
        ksort($tags, SORT_STRING);
        // OTLP metric names carry no separate field: they are keyed by name alone.
        $key = $field === '' ? $measurement : $measurement.'.'.$field;
        if ($tags === []) {
            return $key;
        }

        $pairs = [];
        foreach ($tags as $name => $value) {
            $pairs[] = $name.'='.$value;
        }

        return $key.'{'.implode(',', $pairs).'}';
    }
}
