<?php

namespace App\Application\Tenancy;

use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;

final class ProvisionedAccount
{
    /**
     * @param  list<string>  $created  which records this call created (user, tenant, membership, environment, status_page)
     * @param  string|null  $temporaryPassword  set only when a new user was created without an explicit password
     */
    public function __construct(
        public readonly User $user,
        public readonly Tenant $tenant,
        public readonly TenantMembership $membership,
        public readonly Environment $environment,
        public readonly string $statusPageSlug,
        public readonly array $created,
        public readonly ?string $temporaryPassword,
    ) {
    }
}
