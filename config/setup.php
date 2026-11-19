<?php

return [
    /*
     * Addresses allowed to run the first-run setup (POST /v1/setup), comma separated.
     * Empty means any address in local/testing; in production setup is refused
     * unless this list is set, so a fresh public install cannot be claimed by
     * whoever reaches /app/setup first.
     */
    'allowed_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('SETUP_ALLOWED_EMAILS', ''))
    ))),
];
