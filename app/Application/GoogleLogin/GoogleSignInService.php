<?php

namespace App\Application\GoogleLogin;

use App\Application\Signup\SignupService;
use App\Application\Tenancy\AuditRecorder;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds or links the account for a verified Google identity (#244).
 *
 * 1. The user already linked to this Google `sub` signs in.
 * 2. Otherwise a user with the same address is linked, but only because
 *    Google says the address is verified (identities without
 *    email_verified=true never get here).
 * 3. Otherwise there is no account: the caller sends the person to the
 *    sign-up form (SignupService rules), or refuses when sign-up is closed.
 *
 * Organizations awaiting approval or suspended stay blocked, as with a password,
 * and so do users a platform admin blocked (#248).
 */
final class GoogleSignInService
{
    public function __construct(private readonly AuditRecorder $audit)
    {
    }

    /** @return User|null null when no account matches */
    public function resolve(GoogleIdentity $identity): ?User
    {
        if (! $identity->emailVerified) {
            throw new GoogleLoginException('email_unverified', 'Google says the address is not verified');
        }

        $user = User::query()->where('google_sub', $identity->sub)->first()
            ?? $this->linkByEmail($identity);

        if ($user === null) {
            return null;
        }

        if ($user->isBlocked()) {
            throw new GoogleLoginException('blocked', 'user blocked by a platform admin');
        }

        if (SignupService::awaitingApproval($user)) {
            throw new GoogleLoginException('pending', 'organization awaiting approval or suspended');
        }

        return $user;
    }

    private function linkByEmail(GoogleIdentity $identity): ?User
    {
        return DB::transaction(function () use ($identity): ?User {
            $user = User::query()->where('email', Str::lower($identity->email))->lockForUpdate()->first();
            if ($user === null) {
                return null;
            }
            if ($user->google_sub !== null) {
                // The address belongs to a user linked to another Google account.
                throw new GoogleLoginException('mismatch', 'email linked to another Google account');
            }

            $changes = ['google_sub' => $identity->sub];
            if ($user->email_verified_at === null) {
                // An unconfirmed password sign-up for this address: whoever chose that password never proved
                // the mailbox, Google just did. Drop the password and the sessions so that person cannot keep access.
                $changes['email_verified_at'] = now();
                $changes['password'] = Str::password(40);
                $changes['remember_token'] = Str::random(60);
                if (config('session.driver') === 'database') {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
            }
            $user->forceFill($changes)->save();
            $this->audit->record($user, 'user.google_linked', 'user', $user->public_id, null, [
                'email' => $user->email, 'was_unverified' => isset($changes['email_verified_at']),
            ]);

            return $user;
        });
    }
}
