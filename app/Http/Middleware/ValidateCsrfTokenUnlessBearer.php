<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;

/**
 * CSRF verification for the web group, skipped for token-authenticated requests (#153).
 *
 * The /v1 API runs in the web group so the SPA can use its session, which put
 * CSRF verification in front of API-key requests: every write with
 * "Authorization: Bearer sc_…" answered 419. A browser never attaches an
 * Authorization header to a cross-site request without a CORS preflight, and
 * this app sends no CORS headers for /v1, so requests that carry a bearer token
 * cannot be forged and need no CSRF token. Session requests from the SPA are
 * still verified.
 *
 * ping/* is exempt for the same reason as ingest/*: the token in the path is
 * the credential, and heartbeat senders (cron jobs, scripts) have no session.
 * telegram/webhook/* likewise: Telegram's servers authenticate with the
 * webhook secret in the path and a header (#190).
 */
class ValidateCsrfTokenUnlessBearer extends ValidateCsrfToken
{
    /** @var list<string> */
    protected $except = [
        'ping/*',
        'ingest/*',
        'telegram/webhook/*',
        'whatsapp/webhook/*',
    ];

    public function handle($request, Closure $next)
    {
        if ($this->hasBearerToken($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    private function hasBearerToken(Request $request): bool
    {
        $header = (string) $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ') && trim(substr($header, 7)) !== '';
    }
}
