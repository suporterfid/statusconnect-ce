<?php

namespace App\Domain\Shared;

/**
 * Who may change whose organization membership. Framework-free on purpose:
 * every rule about roles lives here so the API, the SPA and the tests agree.
 *
 * A platform admin is passed in as an OWNER actor.
 */
final class MembershipRules
{
    public static function canList(?TenantRole $actor): bool
    {
        return $actor !== null;
    }

    /** May the actor give someone the role $granted (adding or changing)? */
    public static function canGrant(?TenantRole $actor, TenantRole $granted): bool
    {
        return match ($actor) {
            TenantRole::OWNER => true,
            TenantRole::ADMIN => $granted !== TenantRole::OWNER,
            default => false,
        };
    }

    /** May the actor change or remove a member who currently has $target? */
    public static function canManage(?TenantRole $actor, TenantRole $target): bool
    {
        return match ($actor) {
            TenantRole::OWNER => true,
            TenantRole::ADMIN => $target !== TenantRole::OWNER,
            default => false,
        };
    }

    /**
     * Would this change leave the organization without an owner?
     * $ownerCount is the number of owners before the change.
     */
    public static function removesLastOwner(TenantRole $current, ?TenantRole $next, int $ownerCount): bool
    {
        return $current === TenantRole::OWNER && $next !== TenantRole::OWNER && $ownerCount <= 1;
    }
}
