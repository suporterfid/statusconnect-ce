<?php

namespace App\Application\Monitoring\Services;

use App\Application\RateLimiting\DatabaseRateLimiter;
use App\Domain\Monitoring\MonitorKind;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Shared\Clock;

final class HeartbeatPingService
{
    public function __construct(
        private readonly DatabaseRateLimiter $rateLimiter,
    ) {
    }

    public function recordPing(string $token, string $ip): ?bool
    {
        // 1. Rate limiting per IP and per Token
        $ipRetry = $this->rateLimiter->tryHit('ping_ip_'.$ip, 60, 60);
        if ($ipRetry !== null) {
            return false; // Rate limited
        }

        $tokenRetry = $this->rateLimiter->tryHit('ping_token_'.md5($token), 60, 60);
        if ($tokenRetry !== null) {
            return false; // Rate limited
        }

        // 2. Token lookup
        $monitor = Monitor::query()
            ->where('heartbeat_token', $token)
            ->where('kind', MonitorKind::HEARTBEAT)
            ->where('enabled', true)
            ->first();

        if ($monitor === null) {
            // Constant-time mitigation to prevent token enumeration timing attacks (§11.5)
            hash('sha256', $token);
            usleep(5000);

            return null; // Not found (404)
        }

        // 3. Cheap single indexed UPDATE (no check row, no synchronous notification)
        $monitor->update([
            'last_ping_at' => now(),
        ]);

        return true; // Success (204)
    }
}
