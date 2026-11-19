<?php

namespace App\Http\Middleware;

use App\Application\StatusPages\StatusPageSlugRedirects;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * #248: a public status page address whose slug was renamed answers 301 to the
 * current address, keeping the rest of the path and the query string. Only
 * looked up when the page itself answered 404, so live pages pay nothing.
 */
final class RedirectRenamedStatusPage
{
    public function __construct(private readonly StatusPageSlugRedirects $redirects)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $slug = $request->route('slug');
        if ($response->getStatusCode() !== 404 || ! is_string($slug) || $request->attributes->get('status_base') === '') {
            return $response;
        }

        $current = $this->redirects->resolve($slug);
        if ($current === null) {
            return $response;
        }

        $path = preg_replace('#^/status/'.preg_quote($slug, '#').'(?=/|\.json$|$)#', '/status/'.$current, $request->getPathInfo(), 1);
        $query = $request->getQueryString();

        return redirect()->to(url($path).($query !== null ? '?'.$query : ''), 301);
    }
}
