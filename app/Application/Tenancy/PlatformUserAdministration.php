<?php

namespace App\Application\Tenancy;

use App\Application\Signup\SignupService;
use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Mail\AccountMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * User administration by platform admins (#248): create, edit, block and
 * unblock, end sessions. Platform admins only (enforced by the controller).
 * Every change is audited. Deleting users is out of scope (#249).
 */
final class PlatformUserAdministration
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly MembershipService $members,
        private readonly UserSessions $sessions,
        private readonly SignupService $signup,
    ) {
    }

    /**
     * Creates a user with an unknown random password and emails a link to choose one
     * (the forgot-password flow). Choosing it also confirms the address.
     */
    public function create(User $actor, string $name, string $email, ?Tenant $tenant, ?TenantRole $role): User
    {
        $email = Str::lower(trim($email));
        $this->assertEmailFree($email, null);

        $user = DB::transaction(function () use ($actor, $name, $email, $tenant, $role): User {
            $user = User::query()->forceCreate([
                'name' => trim($name),
                'email' => $email,
                'password' => Str::password(40),
                'email_verified_at' => null,
            ]);
            $this->audit->record($actor, 'user.created', 'user', $user->public_id, $tenant, [
                'email' => $email, 'by' => 'platform_admin',
            ]);

            if ($tenant !== null) {
                // Same rules and audit entry as adding a member from the organization.
                $this->members->add($actor, $tenant, $email, null, $role ?? TenantRole::VIEWER);
            }

            return $user;
        });

        $this->sendSetPasswordLink($user);

        return $user;
    }

    /**
     * Changes name and/or email. A new email is unique, starts unverified with a
     * confirmation link, and unlinks Google, which was tied to the old address.
     */
    public function update(User $actor, User $user, ?string $name, ?string $email): User
    {
        $changes = [];
        $emailChanged = false;

        if ($name !== null && trim($name) !== $user->name) {
            $changes['name'] = ['from' => $user->name, 'to' => trim($name)];
            $user->name = trim($name);
        }

        if ($email !== null) {
            $email = Str::lower(trim($email));
            if ($email !== $user->email) {
                if ($actor->is($user)) {
                    throw ValidationException::withMessages(['email' => [__('messages.cannot_change_own_email')]]);
                }
                $this->assertEmailFree($email, $user);
                $changes['email'] = ['from' => $user->email, 'to' => $email];
                $changes['google_unlinked'] = $user->google_sub !== null;
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                $user->forceFill(['email' => $email, 'email_verified_at' => null, 'google_sub' => null]);
                $emailChanged = true;
            }
        }

        if ($changes === []) {
            return $user;
        }

        $user->save();
        $this->audit->record($actor, 'user.updated', 'user', $user->public_id, null, $changes);

        if ($emailChanged) {
            $this->inRecipientLocale($user, fn () => $this->signup->sendVerification($user));
        }

        return $user;
    }

    public function block(User $actor, User $user): User
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['user' => [__('messages.cannot_block_self')]]);
        }
        if ($user->isBlocked()) {
            return $user;
        }

        DB::transaction(function () use ($user): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($locked->isPlatformAdmin() && $this->activePlatformAdmins() <= 1) {
                throw ValidationException::withMessages(['user' => [__('messages.last_platform_admin')]]);
            }
            $user->forceFill(['blocked_at' => now()])->save();
        });

        $ended = $this->sessions->endAll($user);
        $this->audit->record($actor, 'user.blocked', 'user', $user->public_id, null, [
            'email' => $user->email, 'database_sessions' => $ended['database_sessions'], 'tokens' => $ended['tokens'],
        ]);

        return $user->refresh();
    }

    public function unblock(User $actor, User $user): User
    {
        if (! $user->isBlocked()) {
            return $user;
        }

        $user->forceFill(['blocked_at' => null])->save();
        $this->audit->record($actor, 'user.unblocked', 'user', $user->public_id, null, ['email' => $user->email]);

        return $user;
    }

    /** @return array{database_sessions: int, tokens: int} */
    public function endSessions(User $actor, User $user): array
    {
        $ended = $this->sessions->endAll($user);
        $this->audit->record($actor, 'user.sessions_ended', 'user', $user->public_id, null, ['email' => $user->email] + $ended);

        return $ended;
    }

    /** Platform admins who are not blocked. */
    public function activePlatformAdmins(): int
    {
        return User::query()->where('is_platform_admin', true)->whereNull('blocked_at')->count();
    }

    /** Sign-in methods the user has: a password always exists (it can be reset), Google when linked. */
    public static function signInMethods(User $user): array
    {
        $seen = (array) ($user->sign_in_methods ?? []);

        return array_values(array_filter(UserSessions::METHODS, fn (string $method): bool => match ($method) {
            'password' => true,
            'google' => $user->google_sub !== null,
            default => in_array($method, $seen, true),
        }));
    }

    private function assertEmailFree(string $email, ?User $except): void
    {
        $taken = User::query()->where('email', $email)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['email' => [__('messages.user_email_taken')]]);
        }
    }

    private function sendSetPasswordLink(User $user): void
    {
        $token = Password::broker()->createToken($user);
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

        $this->inRecipientLocale($user, function () use ($user, $url): void {
            try {
                Mail::to($user->email)->send(new AccountMail('invite', __('messages.mail_invite_subject', ['app' => config('app.name')]), [
                    'name' => $user->name,
                    'url' => $url,
                    'minutes' => (int) config('auth.passwords.users.expire', 60),
                    'forgotUrl' => route('password.request'),
                ]));
            } catch (Throwable $e) {
                Log::warning('account_mail.failed', ['kind' => 'invite', 'error' => $e->getMessage()]);
            }
        });
    }

    /** Account emails go out in the recipient's language, else the sign-up language, not the admin's. */
    private function inRecipientLocale(User $user, callable $send): void
    {
        $previous = app()->getLocale();
        $locale = str_replace('-', '_', (string) ($user->preferences?->locale ?? config('signup.locale', 'pt_BR')));
        app()->setLocale(in_array($locale, ['en', 'pt_BR'], true) ? $locale : 'pt_BR');
        try {
            $send();
        } finally {
            app()->setLocale($previous);
        }
    }
}
