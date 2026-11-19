<?php

namespace App\Application\Signup;

use App\Infrastructure\Persistence\Eloquent\Tenant;

/** No abuse limits beyond the sign-up modes (#250). */
final class NoSignupLimits implements SignupLimits
{
    public function assertCanSignUp(string $emailDomain): void
    {
    }

    public function recordSignUp(): void
    {
    }

    public function assertNewAccountRate(Tenant $tenant, string $kind, int $intervalSeconds, ?int $exceptMonitorId = null): void
    {
    }
}
