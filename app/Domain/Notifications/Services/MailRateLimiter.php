<?php

namespace App\Domain\Notifications\Services;

use App\Application\RateLimiting\DatabaseRateLimiter;

final class MailRateLimiter
{
    public function __construct(private readonly DatabaseRateLimiter $rateLimiter)
    {
    }

    public function allowsEmail(int $tenantId, int $maxPerHour = 100): bool
    {
        $bucketKey = sprintf('notify_mail_tenant_%d', $tenantId);
        $retryAfter = $this->rateLimiter->tryHit($bucketKey, $maxPerHour, 3600);

        return $retryAfter === null;
    }
}
