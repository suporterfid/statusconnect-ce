<?php

namespace App\Application\GoogleLogin;

use RuntimeException;

/**
 * A Google sign-in that cannot go on. `reason` is a short code the login
 * page turns into a message (failed, cancelled, email_unverified, no_account,
 * pending, mismatch, blocked); the exception message is for the log only.
 */
final class GoogleLoginException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
