<?php

/*
 * Second-location confirmation (#128).
 *
 * Before a failure opens an incident, the checker asks another location to
 * run the same check. Providers are tried in order; the first one that
 * answers decides. If none answers in time, the incident opens as before, so
 * this never hides an outage. Off by default.
 *
 * See docs/deployment/second-location.md.
 */
return [
    'enabled' => (bool) env('SECOND_LOCATION_ENABLED', false),

    // Tried in this order. Known: probe, globalping.
    'providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('SECOND_LOCATION_PROVIDERS', 'probe,globalping'))))),

    // At most this many confirmations per checker tick; failures beyond it open without confirmation.
    'max_per_tick' => (int) env('SECOND_LOCATION_MAX_PER_TICK', 10),

    // After a second location sees the target up, failures are not re-confirmed for this long.
    'cooldown_seconds' => (int) env('SECOND_LOCATION_COOLDOWN_SECONDS', 300),

    // Seconds of tick budget kept free; with less left, failures open without confirmation.
    'reserve_seconds' => (int) env('SECOND_LOCATION_RESERVE_SECONDS', 3),

    // Remote probe (probe/ in this repository).
    'probe' => [
        'url' => (string) env('SECOND_LOCATION_PROBE_URL', ''),
        'secret' => (string) env('SECOND_LOCATION_PROBE_SECRET', ''),
        'timeout_seconds' => (int) env('SECOND_LOCATION_PROBE_TIMEOUT', 12),
        // Who hosts the probe, named in the Privacy Policy, e.g. "Google Cloud, em São Paulo".
        'host_label' => (string) env('SECOND_LOCATION_PROBE_HOST_LABEL', ''),
    ],

    // Globalping (https://globalping.io). Measurement data is public: only
    // monitors without custom headers or body, using GET or HEAD, are sent.
    'globalping' => [
        'api_url' => (string) env('GLOBALPING_API_URL', 'https://api.globalping.io/v1'),
        'token' => (string) env('GLOBALPING_TOKEN', ''),
        'location' => (string) env('GLOBALPING_LOCATION', 'BR'),
        'timeout_seconds' => (int) env('GLOBALPING_TIMEOUT', 10),
    ],
];
