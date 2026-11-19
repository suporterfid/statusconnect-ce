<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a tenant's status page on its own hostname.
 *
 * A request whose Host matches a status page's custom_domain is rewritten,
 * before routing, onto the normal public routes (/ to /status/{slug},
 * /history to /status/{slug}/history, and so on). Every other path on that
 * host answers 404, so operator routes are never reachable through a
 * tenant's domain. Requests to the application's own host skip the lookup.
 */
final class ServeCustomStatusDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === '' || $host === $appHost) {
            return $next($request);
        }

        $slug = DB::table('status_pages')->where('custom_domain', $host)->value('slug');
        if ($slug === null) {
            return $next($request);
        }

        $target = $this->map($request->getPathInfo(), $slug);
        if ($target === null || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            abort(404);
        }

        $server = $request->server->all();
        $query = $request->getQueryString();
        $server['REQUEST_URI'] = $target.($query !== null && $query !== '' ? '?'.$query : '');
        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $server,
        );
        $request->attributes->set('status_base', '');

        return $next($request);
    }

    private function map(string $path, string $slug): ?string
    {
        $base = '/status/'.$slug;
        $path = '/'.trim($path, '/');

        return match (true) {
            $path === '/' => $base,
            $path === '/status.json' => $base.'.json',
            in_array($path, ['/history', '/calendar', '/feed', '/logo'], true) => $base.$path,
            preg_match('#^/components/(\d+)$#', $path, $m) === 1 => $base.'/components/'.$m[1],
            default => null,
        };
    }
}
