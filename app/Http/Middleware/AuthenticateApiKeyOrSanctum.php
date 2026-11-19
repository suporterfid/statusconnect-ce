<?php

// Ported / mirrored from TaskConnect: app/Http/Middleware/AuthenticateApiKeyOrSanctum.php

namespace App\Http\Middleware;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\GrandpaSson\IntrospectionClientInterface;
use App\Auth\ApiKeyActor;
use App\Application\Tenancy\UserSessions;
use App\Auth\GrandpaSsonActor;
use App\Infrastructure\Persistence\Eloquent\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts native `sc_*` API keys, opt-in GrandpaSSOn opaque tokens, or sessions.
 */
class AuthenticateApiKeyOrSanctum
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
        private readonly IntrospectionClientInterface $introspection,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractBearerToken($request);

        if ($token !== null && str_starts_with($token, 'sc_')) {
            $apiKey = $this->apiKeyService->authenticate($token);

            if ($apiKey === null) {
                abort(401);
            }

            $request->attributes->set('api_key', $apiKey);
            Auth::setUser(new ApiKeyActor($apiKey));

            return $next($request);
        }

        if ($token !== null && (bool) config('grandpasson.inbound_enabled', false)) {
            try {
                $result = $this->introspection->introspect($token);
            } catch (\Throwable) {
                abort(401);
            }

            if ($result->active) {
                $request->attributes->set('grandpasson', $result);
                Auth::setUser(new GrandpaSsonActor($result, hash('sha256', $token)));

                return $next($request);
            }
        }

        if ($request->user() !== null) {
            $this->refuseEndedSession($request, $request->user());

            return $next($request);
        }

        foreach (['sanctum', 'web'] as $guard) {
            $user = Auth::guard($guard)->user();

            if ($user !== null) {
                $this->refuseEndedSession($request, $user);
                Auth::setUser($user);

                return $next($request);
            }
        }

        abort(401);
    }

    /**
     * #248: a blocked user, or a session started before a platform admin ended
     * the user's sessions, is signed out here. This also covers file sessions,
     * which cannot be deleted per user.
     */
    private function refuseEndedSession(Request $request, mixed $user): void
    {
        if (! $user instanceof User) {
            return;
        }
        if (! $user->isBlocked() && UserSessions::isCurrent($request, $user)) {
            return;
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        abort(401);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if ($header === null || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }
}
