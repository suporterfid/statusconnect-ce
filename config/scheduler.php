<?php

return [
    'target_duration_seconds' => (int) env('CHECK_TARGET_DURATION_SECONDS', 45),
    'budget_safety_margin_seconds' => (int) env('CHECK_BUDGET_SAFETY_MARGIN_SECONDS', 5),
    'execution_reserve_seconds' => (int) env('CHECK_EXECUTION_RESERVE_SECONDS', 1),
    'claim_ttl_minutes' => (int) env('CHECK_CLAIM_TTL_MINUTES', 5),
    'stale_claim_recovery_batch_size' => (int) env('CHECK_STALE_CLAIM_RECOVERY_BATCH_SIZE', 50),
    'check_concurrency' => (int) env('CHECK_CONCURRENCY', 10),
    'check_wave_size' => env('CHECK_WAVE_SIZE'),

    // A tick that starts this many minutes or more after the previous one
    // records a checker gap and emails the platform admins (#199). The same
    // limit makes GET /v1/platform/health?checker=1 answer 503.
    'gap_minutes' => (int) env('CHECKER_GAP_MINUTES', 3),

    // Saturation (#214): a tick that leaves due monitors for the next one, or
    // runs this long, is saturated. After saturation_ticks of them in a row the
    // platform admins are emailed (at most once per saturation_alert_hours) and
    // GET /v1/platform/health?checker=1 answers 503.
    'saturation_duration_seconds' => (int) env('CHECKER_SATURATION_SECONDS', 30),
    'saturation_ticks' => (int) env('CHECKER_SATURATION_TICKS', 3),
    'saturation_alert_hours' => (int) env('CHECKER_SATURATION_ALERT_HOURS', 6),

    // Network checks one tick handles, as measured on this host with
    // `php artisan monitor:capacity-test` (docs/deployment/capacity.md). Only
    // shown in the platform console against the current demand; empty = not measured.
    'capacity_per_minute' => env('CHECKER_CAPACITY_PER_MINUTE'),
];
