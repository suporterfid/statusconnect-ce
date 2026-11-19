<?php

return [
    'public_rate_limit_per_minute' => (int) env('STATUS_PAGE_PUBLIC_RATE_LIMIT_PER_MINUTE', 60),

    // Availability counts only confirmed failures (#237): a down/degraded streak
    // that never reached the monitor's confirmation threshold counts as up.
    // true restores the old behavior, where every failed check counted.
    'count_unconfirmed_failures' => (bool) env('STATUS_COUNT_UNCONFIRMED_FAILURES', false),

    // Email subscriptions (#92).
    'subscriptions' => [
        // Emails sent per minute-cron run, across all pages. Keep it under the SMTP provider's limits.
        'mail_per_minute' => (int) env('STATUS_PAGE_SUBSCRIBER_MAIL_PER_MINUTE', 20),
        // New (unconfirmed) subscriptions accepted per page per hour.
        'new_per_page_per_hour' => (int) env('STATUS_PAGE_SUBSCRIBER_NEW_PER_HOUR', 30),
        'max_per_page' => (int) env('STATUS_PAGE_SUBSCRIBER_MAX_PER_PAGE', 5000),
        // Hours a confirmation link stays valid; unconfirmed subscriptions are deleted after this.
        'confirmation_hours' => (int) env('STATUS_PAGE_SUBSCRIBER_CONFIRMATION_HOURS', 48),
    ],
];
