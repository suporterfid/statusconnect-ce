<?php

namespace App\Http\Presenters;

use App\Application\Legal\TermsAcceptanceService;
use App\Application\Tenancy\PlatformUserAdministration;
use App\Http\Support\ApiDate;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;

/**
 * JSON shapes of the platform administration API (#248).
 */
final class PlatformPresenter
{
    /** One row of the users list. */
    public static function user(User $u): array
    {
        return [
            'id' => $u->public_id,
            'name' => $u->name,
            'email' => $u->email,
            'is_platform_admin' => (bool) $u->is_platform_admin,
            'blocked' => $u->isBlocked(),
            'email_verified' => $u->email_verified_at !== null,
            'terms_accepted' => app(TermsAcceptanceService::class)->latest($u),
            'memberships' => $u->memberships
                ->filter(fn ($m) => $m->tenant !== null)
                ->map(fn ($m) => ['id' => $m->public_id, 'tenant' => ['id' => $m->tenant->public_id, 'name' => $m->tenant->name], 'role' => $m->role])
                ->values(),
            'last_login_at' => ApiDate::format($u->last_login_at),
            'created_at' => ApiDate::format($u->created_at),
        ];
    }

    /** The user detail view: the list row plus dates and sign-in methods. */
    public static function userDetail(User $u): array
    {
        $u->loadMissing('memberships.tenant');

        return self::user($u) + [
            'email_verified_at' => ApiDate::format($u->email_verified_at),
            'blocked_at' => ApiDate::format($u->blocked_at),
            'last_login_method' => $u->last_login_method,
            'sign_in_methods' => PlatformUserAdministration::signInMethods($u),
            'updated_at' => ApiDate::format($u->updated_at),
        ];
    }

    public static function member(TenantMembership $m): array
    {
        return [
            'id' => $m->public_id,
            'role' => $m->role,
            'user' => [
                'id' => $m->user->public_id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'blocked' => $m->user->isBlocked(),
                'is_platform_admin' => (bool) $m->user->is_platform_admin,
            ],
            'created_at' => ApiDate::format($m->created_at),
        ];
    }
}
