<?php

namespace App\Application\Tenancy;

use App\Application\StatusPages\StatusPageCacheWriter;
use App\Domain\Shared\TenantRole;
use App\Domain\StatusPage\StatusPageLocale;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates (or completes) an account in one transaction: user, tenant, membership,
 * the default `production` environment and its status page.
 *
 * Every step is find-or-create, so running it again with the same input creates
 * no duplicate rows. That is what lets the same call add a second user to an
 * existing tenant: the tenant, environment and status page are found, and only
 * the new user and membership are created.
 */
final class AccountProvisioningService
{
    public const DEFAULT_ENVIRONMENT_SLUG = 'production';

    public function __construct(private readonly StatusPageCacheWriter $statusPages)
    {
    }

    /**
     * @param  array{
     *     email: string,
     *     name: string,
     *     org: string,
     *     slug: string,
     *     role?: TenantRole,
     *     password?: string|null,
     *     title?: string|null,
     *     locale?: string,
     *     timezone?: string,
     *     platform_admin?: bool,
     *     email_verified?: bool,
     * }  $input
     */
    public function provision(array $input): ProvisionedAccount
    {
        $role = $input['role'] ?? TenantRole::OWNER;
        $email = Str::lower(trim($input['email']));
        $slug = Str::lower(trim($input['slug']));

        $account = DB::transaction(function () use ($input, $role, $email, $slug): ProvisionedAccount {
            $created = [];
            $temporaryPassword = null;

            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $password = $input['password'] ?? null;
                if ($password === null || $password === '') {
                    $password = $temporaryPassword = Str::password(20, symbols: false);
                }

                // Created by an admin, the setup page or the CLI: no confirmation link
                // is sent, so the address counts as verified (#174). Self-service
                // sign-up passes email_verified=false and sends the link itself.
                $user = User::query()->forceCreate([
                    'name' => $input['name'],
                    'email' => $email,
                    'password' => $password,
                    'is_platform_admin' => (bool) ($input['platform_admin'] ?? false),
                    'email_verified_at' => ($input['email_verified'] ?? true) ? now() : null,
                ]);
                $created[] = 'user';
            }

            $tenant = Tenant::query()->where('slug', $slug)->first();
            if ($tenant === null) {
                $tenant = Tenant::query()->create(['name' => $input['org'], 'slug' => $slug]);
                $created[] = 'tenant';
            }

            $membership = TenantMembership::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->first();
            if ($membership === null) {
                $membership = TenantMembership::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'role' => $role->value,
                ]);
                $created[] = 'membership';
            }

            $environment = Environment::query()
                ->where('tenant_id', $tenant->id)
                ->where('slug', self::DEFAULT_ENVIRONMENT_SLUG)
                ->first();
            if ($environment === null) {
                $environment = Environment::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => 'Production',
                    'slug' => self::DEFAULT_ENVIRONMENT_SLUG,
                ]);
                $created[] = 'environment';
            }

            $statusPage = DB::table('status_pages')->where('environment_id', $environment->id)->first();
            if ($statusPage === null) {
                if (DB::table('status_pages')->where('slug', $slug)->exists()) {
                    throw new \DomainException("Status page slug [{$slug}] is already used by another environment.");
                }

                $now = now();
                $id = DB::table('status_pages')->insertGetId([
                    'tenant_id' => $tenant->id,
                    'environment_id' => $environment->id,
                    'slug' => $slug,
                    'title' => $input['title'] ?? $input['org'],
                    'visibility' => 'public',
                    'locale' => StatusPageLocale::normalize($input['locale'] ?? 'en'),
                    'timezone' => $input['timezone'] ?? 'UTC',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $statusPage = DB::table('status_pages')->where('id', $id)->first();
                $created[] = 'status_page';
            }

            return new ProvisionedAccount(
                user: $user,
                tenant: $tenant,
                membership: $membership,
                environment: $environment,
                statusPageSlug: $statusPage->slug,
                created: $created,
                temporaryPassword: $temporaryPassword,
            );
        });

        // The public route only serves pre-rendered snapshots; render the new page now
        // so /status/{slug} answers 200 without waiting for the next monitor:rollup tick.
        $this->statusPages->refreshStalePages();

        return $account;
    }
}
