<?php

namespace App\Domain\Monitoring\Services;

final class HeartbeatTokenGenerator
{
    public function generate(): string
    {
        return 'hb_'.bin2hex(random_bytes(16));
    }
}
