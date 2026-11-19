<?php

// Agent metric ingestion (docs/specs/agent-ingestion-addendum.md §7).
return [
    'enabled' => filter_var(env('AGENT_INGEST_ENABLED', false), FILTER_VALIDATE_BOOL),
    'max_bytes' => (int) env('AGENT_INGEST_MAX_BYTES', 262144),
    'max_decompressed_bytes' => (int) env('AGENT_INGEST_MAX_DECOMPRESSED_BYTES', 1048576),
    // Line protocol lines, or OTLP data points, per request.
    'max_lines' => (int) env('AGENT_INGEST_MAX_LINES', 2000),
    // OTLP: resource attributes copied into metric keys, and the most data point attributes a key may carry.
    'otlp_resource_attributes' => array_values(array_filter(array_map('trim', explode(',', (string) env('AGENT_OTLP_RESOURCE_ATTRIBUTES', 'host.name,service.name'))))),
    'otlp_max_attributes' => (int) env('AGENT_OTLP_MAX_ATTRIBUTES', 8),
    'rate_per_token_per_minute' => (int) env('AGENT_INGEST_RATE_PER_TOKEN_PER_MINUTE', 12),
    'rate_per_ip_per_minute' => (int) env('AGENT_INGEST_RATE_PER_IP_PER_MINUTE', 60),
    'max_metrics_per_monitor' => (int) env('AGENT_MAX_METRICS_PER_MONITOR', 200),
    'max_rules_per_monitor' => (int) env('AGENT_MAX_RULES_PER_MONITOR', 50),
    'metric_key_max_length' => (int) env('AGENT_METRIC_KEY_MAX_LENGTH', 255),
    'retention_days' => (int) env('RETENTION_AGENT_METRIC_DAYS', 30),
];
