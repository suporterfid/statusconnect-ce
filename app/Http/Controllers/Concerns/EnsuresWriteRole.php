<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\Request;

trait EnsuresWriteRole
{
    /**
     * Session users need an owner or admin membership (platform admins always pass).
     * API keys and broker actors are already scoped to the tenant by tenant.context.
     */
    private function ensureCanWrite(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User || $user->isPlatformAdmin()) {
            return;
        }

        $role = $user->memberships()->where('tenant_id', $request->attributes->get('tenant')->id)->value('role');
        if (! TenantRole::tryFrom((string) $role)?->canWrite()) {
            abort(403, __('messages.write_role_required'));
        }
    }
}
