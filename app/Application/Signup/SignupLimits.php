<?php

namespace App\Application\Signup;

use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Abuse limits for self-service sign-up, beyond the sign-up modes (#218, #250).
 *
 * The core has none (NoSignupLimits): the route throttles and the blocked
 * domains apply, nothing else.
 */
interface SignupLimits
{
    /** @throws ValidationException when one more sign-up for this email domain is not allowed now */
    public function assertCanSignUp(string $emailDomain): void;

    /** Called after a sign-up succeeded. */
    public function recordSignUp(): void;

    /**
     * New self-service organizations: whether one more enabled monitor of this
     * kind, at this interval, stays within the cap of their first days.
     *
     * @param  int|null  $exceptMonitorId  the monitor being changed, counted with its new interval instead
     *
     * @throws ValidationException
     */
    public function assertNewAccountRate(Tenant $tenant, string $kind, int $intervalSeconds, ?int $exceptMonitorId = null): void;
}
