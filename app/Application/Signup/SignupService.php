<?php

namespace App\Application\Signup;

use App\Application\Plans\PlanCatalog;
use App\Application\Tenancy\AccountProvisioningService;
use App\Application\Tenancy\AuditRecorder;
use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Mail\AccountMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Self-service sign-up (#120): creates the owner, the organization on the
 * default plan, its production environment and status page, then emails a
 * verification link. In `approval` mode the organization waits for a
 * platform admin.
 */
final class SignupService
{
    public function __construct(
        private readonly AccountProvisioningService $provisioning,
        private readonly PlanCatalog $plans,
        private readonly AuditRecorder $audit,
        private readonly SignupLimits $limits,
    ) {
    }

    public static function mode(): string
    {
        $mode = (string) config('signup.mode', 'closed');

        return in_array($mode, ['closed', 'approval', 'open'], true) ? $mode : 'closed';
    }

    /**
     * With $googleSub (#244) the address was verified by Google: the account is created verified,
     * linked to that Google account, with a random password (reset it to sign in with a password),
     * and no confirmation link is sent. Every other rule is the same.
     */
    public function register(string $name, string $email, string $password, string $organization, ?string $googleSub = null): User
    {
        $email = Str::lower(trim($email));
        $domain = Str::after($email, '@');
        if (in_array($domain, (array) config('signup.blocked_domains', []), true)) {
            throw ValidationException::withMessages(['email' => [__('messages.signup_email_blocked')]]);
        }
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => [__('messages.signup_email_taken')]]);
        }
        $this->limits->assertCanSignUp($domain);

        $account = DB::transaction(function () use ($name, $email, $password, $organization, $googleSub) {
            $account = $this->provisioning->provision([
                'email' => $email,
                'name' => trim($name),
                'org' => trim($organization),
                'slug' => $this->uniqueSlug($organization),
                'role' => TenantRole::OWNER,
                'password' => $password,
                // Confirmed through the link sent below, or already by Google.
                'email_verified' => $googleSub !== null,
                'locale' => (string) config('signup.locale', 'pt_BR'),
                'timezone' => (string) config('signup.timezone', 'America/Sao_Paulo'),
            ]);

            $account->tenant->forceFill([
                'plan' => $this->plans->defaultPlan(),
                'approved_at' => self::mode() === 'open' ? now() : null,
                // #218: new-account check cap and the daily sign-up digest.
                'signed_up_at' => now(),
            ])->save();
            if ($googleSub !== null) {
                $account->user->forceFill(['google_sub' => $googleSub])->save();
            }

            // The sign-up form requires ticking "I accept the Terms and the Privacy Policy".
            app(\App\Application\Legal\TermsAcceptanceService::class)->accept($account->user, 'signup', request());

            $this->audit->record($account->user, 'signup.created', 'tenant', $account->tenant->public_id, $account->tenant, [
                'email' => $email, 'mode' => self::mode(), 'method' => $googleSub !== null ? 'google' : 'password',
            ]);

            return $account;
        });

        $this->limits->recordSignUp();

        if ($googleSub === null) {
            $this->sendVerification($account->user);
        }
        if (self::mode() === 'approval') {
            $this->notifyPlatformAdmins($account->user, $account->tenant);
        }

        return $account->user;
    }

    public function sendVerification(User $user): void
    {
        // Signed without the host: behind a TLS-terminating proxy the request may arrive as http or under
        // another host name, which would break an absolute signature.
        $url = url(URL::temporarySignedRoute('signup.verify', now()->addHours((int) config('signup.verification_hours', 48)), [
            'user' => $user->public_id,
            'hash' => sha1($user->email),
        ], absolute: false));

        $this->send($user->email, new AccountMail('verify', __('messages.mail_verify_subject', ['app' => config('app.name')]), [
            'name' => $user->name,
            'url' => $url,
            'hours' => (int) config('signup.verification_hours', 48),
        ]));
    }

    /** @return bool true when the link matched and the email is now verified */
    public function verify(User $user, string $hash): bool
    {
        if (! hash_equals(sha1($user->email), $hash)) {
            return false;
        }
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->audit->record($user, 'signup.email_verified', 'user', $user->public_id);
        }

        return true;
    }

    /** True when every organization of the user still waits for approval. */
    public static function awaitingApproval(User $user): bool
    {
        if ($user->isPlatformAdmin()) {
            return false;
        }

        $tenantIds = $user->memberships()->pluck('tenant_id');

        return $tenantIds->isNotEmpty()
            && ! Tenant::query()->whereIn('id', $tenantIds)->whereNotNull('approved_at')->exists();
    }

    public function approve(?User $actor, Tenant $tenant): void
    {
        if ($tenant->isApproved()) {
            return;
        }

        $tenant->forceFill(['approved_at' => now()])->save();
        $this->audit->record($actor, 'signup.approved', 'tenant', $tenant->public_id, $tenant);

        $owners = User::query()
            ->whereIn('id', $tenant->memberships()->where('role', TenantRole::OWNER->value)->pluck('user_id'))
            ->get();
        foreach ($owners as $owner) {
            $this->send($owner->email, new AccountMail('approved', __('messages.mail_approved_subject', ['app' => config('app.name')]), [
                'name' => $owner->name,
                'organization' => $tenant->name,
                'url' => url('/app/login'),
            ]));
        }
    }

    /**
     * Suspends an organization (#218): its members cannot sign in or reach it,
     * and its monitors are not checked, until a platform admin approves it again.
     * Nothing is deleted.
     */
    public function suspend(?User $actor, Tenant $tenant): void
    {
        if (! $tenant->isApproved()) {
            return;
        }

        $tenant->forceFill(['approved_at' => null])->save();
        $this->audit->record($actor, 'tenant.suspended', 'tenant', $tenant->public_id, $tenant);

        // Open sessions of its members end now (database sessions only), unless they belong to other organizations.
        if (config('session.driver') === 'database') {
            foreach ($tenant->memberships()->pluck('user_id') as $userId) {
                $user = User::query()->find($userId);
                if ($user !== null && ! $user->isPlatformAdmin() && self::awaitingApproval($user)) {
                    DB::table('sessions')->where('user_id', $userId)->delete();
                }
            }
        }
    }

    private function notifyPlatformAdmins(User $user, Tenant $tenant): void
    {
        foreach (User::query()->where('is_platform_admin', true)->get() as $admin) {
            $this->send($admin->email, new AccountMail('admin_new_signup', __('messages.mail_admin_signup_subject', ['org' => $tenant->name]), [
                'name' => $user->name,
                'email' => $user->email,
                'organization' => $tenant->name,
                'url' => url('/app/administration'),
            ]));
        }
    }

    private function uniqueSlug(string $organization): string
    {
        $base = Str::slug(Str::limit($organization, 60, ''));
        if ($base === '' || strlen($base) < 3) {
            $base = 'org-'.$base;
        }
        $base = trim($base, '-');

        $slug = $base;
        while (Tenant::query()->where('slug', $slug)->exists() || DB::table('status_pages')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    /** Mail failures are logged, never shown: the account exists and the link can be sent again. */
    private function send(string $to, AccountMail $mail): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (Throwable $e) {
            Log::warning('account_mail.failed', ['kind' => $mail->kind, 'error' => $e->getMessage()]);
        }
    }
}
