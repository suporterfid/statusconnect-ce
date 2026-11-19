<?php

namespace App\Http\Middleware;

use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\ApiKey;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces membership roles and API key permissions on environment routes (#154).
 *
 * Runs after tenant.context, which has already checked that the actor belongs
 * to the tenant. This middleware decides whether the actor may perform the
 * request:
 *
 * - Signed-in users: any member may read; only owners and admins (and platform
 *   admins) may write. Viewers are read-only.
 * - API keys: the key must hold the permission the route needs (see
 *   requiredPermission()). `*` grants everything; any write or operate
 *   permission also grants monitors:read.
 * - GrandpaSSOn tokens are checked by scope in grandpasson.workspace_aud.
 */
class EnforceTenantPermission
{
    public const READ = 'monitors:read';

    public const WRITE = 'monitors:write';

    public const OPERATE = 'monitors:operate';

    public const SECRETS = 'secrets:manage';

    public const API_KEYS = 'api_keys:manage';

    /** Permissions that also allow reading. */
    private const IMPLIES_READ = [self::WRITE, self::OPERATE, self::SECRETS];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get('tenant');
        if (! $tenant instanceof Tenant || $request->route('environmentId') === null) {
            return $next($request);
        }

        $isRead = in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);

        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey instanceof ApiKey) {
            $permission = self::requiredPermission($request);
            abort_unless(self::keyAllows($apiKey, $permission), 403, __('messages.api_key_permission_denied', ['permission' => $permission]));

            return $next($request);
        }

        $user = $request->user();
        if ($user instanceof User && ! $isRead && ! $this->userCanWrite($user, $tenant)) {
            abort(403, __('messages.viewer_read_only'));
        }

        return $next($request);
    }

    /**
     * The API key permission a request needs, from the resource under the
     * environment and the HTTP method.
     */
    public static function requiredPermission(Request $request): string
    {
        $uri = (string) $request->route()?->uri();
        $tail = preg_replace('#^.*\{environmentId\}/?#', '', $uri) ?? '';
        $resource = explode('/', $tail)[0];
        $isRead = in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);

        if ($resource === 'api-keys') {
            return self::API_KEYS;
        }
        if ($isRead) {
            return self::READ;
        }

        return match (true) {
            // Channels hold webhook URLs, secrets and API keys of other services.
            $resource === 'notification-channels' && str_ends_with($tail, '/test') => self::OPERATE,
            $resource === 'notification-channels' => self::SECRETS,
            // Day-to-day incident handling and status-page notices.
            $resource === 'incidents' => self::OPERATE,
            str_starts_with($tail, 'status-page/updates') => self::OPERATE,
            default => self::WRITE,
        };
    }

    public static function keyAllows(ApiKey $apiKey, string $permission): bool
    {
        if ($apiKey->hasPermission($permission)) {
            return true;
        }
        if ($permission === self::READ) {
            foreach (self::IMPLIES_READ as $implied) {
                if ($apiKey->hasPermission($implied)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function userCanWrite(User $user, Tenant $tenant): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        $role = $user->memberships()->where('tenant_id', $tenant->id)->value('role');

        return $role !== null && TenantRole::tryFrom((string) $role)?->canWrite() === true;
    }
}
