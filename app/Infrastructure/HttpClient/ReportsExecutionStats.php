<?php

namespace App\Infrastructure\HttpClient;

/**
 * An HTTP prober that can say where the checks of a tick ran (#222, #250).
 * The checker adds what takeStats() returns to its heartbeat as `probe`.
 */
interface ReportsExecutionStats
{
    /** @return array<string, mixed> the counters since the last call, which resets them */
    public function takeStats(): array;
}
