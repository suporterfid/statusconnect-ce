<?php

namespace App\Application\StatusPages;

use App\Domain\StatusPage\AccentColor;
use App\Domain\StatusPage\CustomDomain;
use App\Domain\StatusPage\StatusPageLocale;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operator-side writes for an environment's status page and its components.
 *
 * Each write bumps status_pages.updated_at and re-renders stale snapshots, so the
 * public /status/{slug} route (which only reads status_page_cache) reflects the
 * change immediately instead of on the next monitor:rollup tick.
 */
final class StatusPageService
{
    private const WRITABLE = ['slug', 'custom_domain', 'title', 'visibility', 'locale', 'timezone', 'accent_color', 'website_url', 'privacy_url', 'show_powered_by', 'subscriptions_enabled'];

    public function __construct(
        private readonly StatusPageCacheWriter $writer,
        private readonly StatusPageSlugRedirects $redirects,
    ) {
    }

    public function find(Environment $environment): ?object
    {
        return DB::table('status_pages')->where('environment_id', $environment->id)->first();
    }

    /**
     * @param  array{slug?: string, title?: string, visibility?: string, locale?: string, timezone?: string, accent_color?: string|null, website_url?: string|null, privacy_url?: string|null, show_powered_by?: bool}  $data
     */
    public function upsert(Tenant $tenant, Environment $environment, array $data): object
    {
        $page = $this->find($environment);

        if (isset($data['slug'])) {
            $taken = DB::table('status_pages')
                ->where('slug', $data['slug'])
                ->when($page !== null, fn ($q) => $q->where('id', '!=', $page->id))
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['slug' => [__('messages.status_page_slug_taken')]]);
            }
        }

        if (array_key_exists('custom_domain', $data)) {
            $data['custom_domain'] = CustomDomain::normalize($data['custom_domain']);
            if ($data['custom_domain'] !== null) {
                $this->assertDomainAvailable($data['custom_domain'], $page);
            }
        }

        $now = now();
        $fields = array_intersect_key($data, array_flip(self::WRITABLE));
        if (isset($fields['locale'])) {
            $fields['locale'] = StatusPageLocale::normalize($fields['locale']);
        }
        if (array_key_exists('accent_color', $fields)) {
            $fields['accent_color'] = AccentColor::normalize($fields['accent_color']);
        }
        if (array_key_exists('show_powered_by', $fields)) {
            $fields['show_powered_by'] = (bool) $fields['show_powered_by'];
        }
        if (array_key_exists('subscriptions_enabled', $fields)) {
            $fields['subscriptions_enabled'] = (bool) $fields['subscriptions_enabled'];
        }

        if ($page === null) {
            if (! isset($fields['slug'], $fields['title'])) {
                throw ValidationException::withMessages(['slug' => [__('validation.required', ['attribute' => 'slug'])]]);
            }

            DB::table('status_pages')->insert([
                'tenant_id' => $tenant->id,
                'environment_id' => $environment->id,
                ...$fields,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('status_pages')->where('id', $page->id)->update([...$fields, 'updated_at' => $now]);
            // #248: the old address keeps working with a 301.
            if (isset($fields['slug'])) {
                $this->redirects->record($page->id, $page->slug, $fields['slug']);
            }
        }

        $page = $this->find($environment);
        $this->rerender($page->id);

        return $page;
    }

    private function assertDomainAvailable(string $domain, ?object $page): void
    {
        $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! CustomDomain::isValid($domain) || CustomDomain::isReserved($domain, $appHost)) {
            throw ValidationException::withMessages(['custom_domain' => [__('messages.custom_domain_invalid')]]);
        }

        $taken = DB::table('status_pages')
            ->where('custom_domain', $domain)
            ->when($page !== null, fn ($q) => $q->where('id', '!=', $page->id))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['custom_domain' => [__('messages.custom_domain_taken')]]);
        }
    }

    /**
     * @return list<array{id: int, name: string, sort_order: int, monitor_ids: list<string>}>
     */
    public function components(Environment $environment): array
    {
        $page = $this->find($environment);
        if ($page === null) {
            return [];
        }

        $components = DB::table('status_page_components')
            ->where('status_page_id', $page->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $links = DB::table('status_page_component_monitors as link')
            ->join('monitors', 'monitors.id', '=', 'link.monitor_id')
            ->whereIn('link.status_page_component_id', $components->pluck('id'))
            ->get(['link.status_page_component_id', 'monitors.public_id'])
            ->groupBy('status_page_component_id');

        return $components->map(fn (object $c) => [
            'id' => (int) $c->id,
            'name' => $c->name,
            'sort_order' => (int) $c->sort_order,
            'monitor_ids' => $links->get($c->id, collect())->pluck('public_id')->values()->all(),
        ])->values()->all();
    }

    /**
     * @param  array{name: string, sort_order?: int, monitor_ids?: list<string>}  $data
     */
    public function createComponent(Environment $environment, array $data): array
    {
        $page = $this->requirePage($environment);
        $monitorIds = $this->resolveMonitors($environment, $data['monitor_ids'] ?? []);

        $id = DB::transaction(function () use ($page, $data, $monitorIds): int {
            $now = now();
            $id = DB::table('status_page_components')->insertGetId([
                'status_page_id' => $page->id,
                'name' => $data['name'],
                'sort_order' => $data['sort_order']
                    ?? ((int) DB::table('status_page_components')->where('status_page_id', $page->id)->max('sort_order') + 1),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->syncMonitors($id, $monitorIds);
            $this->touch($page->id);

            return $id;
        });

        $this->rerender($page->id);

        return $this->component($environment, $id);
    }

    /**
     * @param  array{name?: string, sort_order?: int, monitor_ids?: list<string>}  $data
     */
    public function updateComponent(Environment $environment, int $componentId, array $data): array
    {
        $page = $this->requirePage($environment);
        $this->requireComponent($page->id, $componentId);
        $monitorIds = array_key_exists('monitor_ids', $data)
            ? $this->resolveMonitors($environment, $data['monitor_ids'] ?? [])
            : null;

        DB::transaction(function () use ($page, $componentId, $data, $monitorIds): void {
            $fields = array_intersect_key($data, array_flip(['name', 'sort_order']));
            DB::table('status_page_components')->where('id', $componentId)->update([...$fields, 'updated_at' => now()]);
            if ($monitorIds !== null) {
                $this->syncMonitors($componentId, $monitorIds);
            }
            $this->touch($page->id);
        });

        $this->rerender($page->id);

        return $this->component($environment, $componentId);
    }

    public function deleteComponent(Environment $environment, int $componentId): void
    {
        $page = $this->requirePage($environment);
        $this->requireComponent($page->id, $componentId);

        DB::transaction(function () use ($page, $componentId): void {
            DB::table('status_page_components')->where('id', $componentId)->delete();
            $this->touch($page->id);
        });

        $this->rerender($page->id);
    }

    private function component(Environment $environment, int $componentId): array
    {
        foreach ($this->components($environment) as $component) {
            if ($component['id'] === $componentId) {
                return $component;
            }
        }

        abort(404);
    }

    private function requirePage(Environment $environment): object
    {
        $page = $this->find($environment);
        abort_if($page === null, 404);

        return $page;
    }

    private function requireComponent(int $pageId, int $componentId): void
    {
        abort_unless(
            DB::table('status_page_components')->where('id', $componentId)->where('status_page_id', $pageId)->exists(),
            404,
        );
    }

    /**
     * Maps monitor public ids to internal ids, refusing any monitor outside this
     * environment so a component can never publish another tenant's monitor.
     *
     * @param  list<string>  $publicIds
     * @return list<int>
     */
    private function resolveMonitors(Environment $environment, array $publicIds): array
    {
        $publicIds = array_values(array_unique($publicIds));
        if ($publicIds === []) {
            return [];
        }

        $ids = Monitor::query()
            ->where('environment_id', $environment->id)
            ->whereIn('public_id', $publicIds)
            ->pluck('id', 'public_id');

        if ($ids->count() !== count($publicIds)) {
            throw ValidationException::withMessages(['monitor_ids' => [__('validation.exists', ['attribute' => 'monitor_ids'])]]);
        }

        return $ids->values()->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $monitorIds
     */
    private function syncMonitors(int $componentId, array $monitorIds): void
    {
        DB::table('status_page_component_monitors')->where('status_page_component_id', $componentId)->delete();
        $now = now();
        foreach ($monitorIds as $monitorId) {
            DB::table('status_page_component_monitors')->insert([
                'status_page_component_id' => $componentId,
                'monitor_id' => $monitorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Drops this page's snapshot and renders it again. Relying on the writer's
     * updated_at > generated_at staleness check alone would miss a second write
     * landing in the same second as the previous render.
     */
    public function rerender(int $pageId): void
    {
        DB::table('status_page_cache')->where('status_page_id', $pageId)->delete();
        $this->writer->refreshStalePages();
    }

    private function touch(int $pageId): void
    {
        DB::table('status_pages')->where('id', $pageId)->update(['updated_at' => now()]);
    }
}
