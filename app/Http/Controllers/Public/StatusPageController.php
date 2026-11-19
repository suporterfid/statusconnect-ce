<?php

namespace App\Http\Controllers\Public;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class StatusPageController
{
    public function show(Request $request, string $slug): Response
    {
        $snapshot = $this->cachedSnapshot($slug, $request->user()?->id);
        if ($snapshot === null) {
            abort(404);
        }

        if ($this->isNotModified($request, $snapshot->etag)) {
            return $this->notModified($snapshot->etag);
        }

        App::setLocale($snapshot->payload['locale']);

        return $this->cacheable(response()->view('status.page', [
            'snapshot' => $snapshot->payload,
            'slug' => $slug,
            'unlisted' => $snapshot->visibility === 'unlisted',
            ...$this->linkBase($request, $slug),
        ]), $snapshot->etag);
    }

    public function history(Request $request, string $slug): Response
    {
        return $this->subPage($request, $slug, 'history', 'status.history');
    }

    public function calendar(Request $request, string $slug): Response
    {
        return $this->subPage($request, $slug, 'calendar', 'status.calendar');
    }

    public function component(Request $request, string $slug, int $componentId): Response
    {
        return $this->subPage($request, $slug, 'component-'.$componentId, 'status.component', function (array $payload) use ($componentId): array {
            foreach ($payload['components'] ?? [] as $component) {
                if (($component['id'] ?? null) === $componentId) {
                    return ['component' => $component];
                }
            }

            abort(404);
        });
    }

    /**
     * Renders another view of the same cached snapshot. The ETag gets a suffix so
     * each page keeps its own conditional-request validator.
     */
    /**
     * Atom feed of public status updates and incidents, built from the snapshot.
     */
    public function feed(Request $request, string $slug): Response
    {
        $response = $this->subPage($request, $slug, 'feed', 'status.feed');
        if ($response->getStatusCode() === 200) {
            $response->headers->set('Content-Type', 'application/atom+xml; charset=UTF-8');
        }

        return $response;
    }

    /**
     * Link base for the views: root-relative "/status/{slug}" on the app host, or
     * "" when ServeCustomStatusDomain is serving the page on a tenant's domain.
     * $origin is the absolute scheme and host, for the feed's absolute links.
     *
     * @return array{base: string, origin: string}
     */
    private function linkBase(Request $request, string $slug): array
    {
        $custom = $request->attributes->get('status_base') === '';

        return [
            'base' => $custom ? '' : '/status/'.$slug,
            'origin' => $custom ? $request->getSchemeAndHttpHost() : rtrim(url('/'), '/'),
        ];
    }

    private function subPage(Request $request, string $slug, string $suffix, string $view, ?callable $extra = null): Response
    {
        $snapshot = $this->cachedSnapshot($slug, $request->user()?->id);
        if ($snapshot === null) {
            abort(404);
        }

        $data = $extra === null ? [] : $extra($snapshot->payload);
        $etag = $snapshot->etag.'-'.$suffix;
        if ($this->isNotModified($request, $etag)) {
            return $this->notModified($etag);
        }

        App::setLocale($snapshot->payload['locale']);

        return $this->cacheable(response()->view($view, [
            'snapshot' => $snapshot->payload,
            'slug' => $slug,
            'unlisted' => $snapshot->visibility === 'unlisted',
            ...$this->linkBase($request, $slug),
            ...$data,
        ]), $etag);
    }

    /**
     * Serves the page's re-encoded PNG logo from the private disk, under the same
     * visibility rules as the page itself.
     */
    public function logo(Request $request, string $slug): Response|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $path = DB::table('status_pages as page')
            ->where('page.slug', $slug)
            ->where(fn ($query) => $this->visibleTo($query, $request->user()?->id))
            ->value('page.logo_path');

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->response($path, 'logo.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function json(Request $request, string $slug): JsonResponse|Response
    {
        $snapshot = $this->cachedSnapshot($slug, $request->user()?->id);
        if ($snapshot === null) {
            abort(404);
        }

        if ($this->isNotModified($request, $snapshot->etag)) {
            return $this->notModified($snapshot->etag);
        }

        return $this->cacheable(response()->json($snapshot->payload), $snapshot->etag);
    }

    private function cachedSnapshot(string $slug, ?int $userId): ?object
    {
        $row = DB::table('status_page_cache as cache')
            ->join('status_pages as page', 'page.id', '=', 'cache.status_page_id')
            ->where('page.slug', $slug)
            ->where(fn ($query) => $this->visibleTo($query, $userId))
            ->select(['cache.payload_json', 'cache.etag', 'page.visibility'])
            ->first();

        if ($row === null) {
            return null;
        }

        try {
            $payload = json_decode($row->payload_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($payload) || ! isset($payload['locale'])) {
            return null;
        }

        return (object) ['etag' => $row->etag, 'payload' => $payload, 'visibility' => $row->visibility];
    }

    /**
     * Public and unlisted pages are open; private pages need a membership in the page's tenant.
     */
    private function visibleTo($query, ?int $userId): void
    {
        $query->whereIn('page.visibility', ['public', 'unlisted']);

        if ($userId !== null) {
            $query->orWhere(function ($private) use ($userId): void {
                $private->where('page.visibility', 'private')
                    ->whereExists(function ($membership) use ($userId): void {
                        $membership->selectRaw('1')
                            ->from('tenant_memberships')
                            ->whereColumn('tenant_memberships.tenant_id', 'page.tenant_id')
                            ->where('tenant_memberships.user_id', $userId);
                    });
            });
        }
    }

    private function isNotModified(Request $request, string $etag): bool
    {
        return $request->header('If-None-Match') === sprintf('"%s"', $etag);
    }

    private function notModified(string $etag): Response
    {
        return $this->cacheable(response('', 304), $etag);
    }

    private function cacheable(Response|JsonResponse $response, string $etag): Response|JsonResponse
    {
        $response->headers->set('Cache-Control', 'public, max-age=60');
        $response->headers->set('ETag', sprintf('"%s"', $etag));

        return $response;
    }
}
