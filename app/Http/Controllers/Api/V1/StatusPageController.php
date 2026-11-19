<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\StatusPages\StatusPageLogoService;
use App\Application\StatusPages\StatusPageService;
use App\Application\StatusPages\StatusPageUpdateService;
use App\Http\Controllers\Concerns\EnsuresWriteRole;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\StatusPageUpdate;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StatusPageController extends Controller
{
    use EnsuresWriteRole;

    private const SLUG_RULE = 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private readonly StatusPageService $pages,
        private readonly StatusPageUpdateService $updates,
        private readonly StatusPageLogoService $logos,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $page = $this->pages->find($this->environment($request));

        return response()->json(['data' => $page === null ? null : $this->present($page)]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureCanWrite($request);
        $environment = $this->environment($request);
        $exists = $this->pages->find($environment) !== null;
        $required = $exists ? 'sometimes' : 'required';

        $validated = $request->validate([
            'slug' => [$required, 'string', 'max:120', self::SLUG_RULE],
            'title' => [$required, 'string', 'max:255'],
            'visibility' => ['sometimes', 'in:public,unlisted,private'],
            'locale' => ['sometimes', 'in:en,pt_BR,pt-BR'],
            'timezone' => ['sometimes', 'timezone'],
            'accent_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'website_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'privacy_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'show_powered_by' => ['sometimes', 'boolean'],
            'subscriptions_enabled' => ['sometimes', 'boolean'],
            'custom_domain' => ['sometimes', 'nullable', 'string', 'max:253'],
        ]);

        // Plan limits (#119): setting a custom domain needs a plan that includes one.
        if (! empty($validated['custom_domain'])) {
            app(\App\Application\Plans\PlanLimits::class)->assertCustomDomain($this->tenant($request));
        }

        $page = $this->pages->upsert($this->tenant($request), $environment, $validated);

        return response()->json(['data' => $this->present($page)], $exists ? 200 : 201);
    }

    public function components(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->pages->components($this->environment($request))]);
    }

    public function storeComponent(Request $request): JsonResponse
    {
        $this->ensureCanWrite($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'monitor_ids' => ['sometimes', 'array'],
            'monitor_ids.*' => ['string'],
        ]);

        return response()->json(['data' => $this->pages->createComponent($this->environment($request), $validated)], 201);
    }

    public function updateComponent(Request $request, string $tenantId, string $environmentId, int $componentId): JsonResponse
    {
        $this->ensureCanWrite($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'monitor_ids' => ['sometimes', 'array'],
            'monitor_ids.*' => ['string'],
        ]);

        return response()->json(['data' => $this->pages->updateComponent($this->environment($request), $componentId, $validated)]);
    }

    public function destroyComponent(Request $request, string $tenantId, string $environmentId, int $componentId): JsonResponse
    {
        $this->ensureCanWrite($request);
        $this->pages->deleteComponent($this->environment($request), $componentId);

        return response()->json(null, 204);
    }

    public function storeLogo(Request $request): JsonResponse
    {
        $this->ensureCanWrite($request);
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:1024', 'dimensions:max_width=4000,max_height=4000'],
        ]);

        return response()->json(['data' => $this->present($this->logos->store($this->environment($request), $request->file('logo')))]);
    }

    public function destroyLogo(Request $request): JsonResponse
    {
        $this->ensureCanWrite($request);

        return response()->json(['data' => $this->present($this->logos->remove($this->environment($request)))]);
    }

    public function updates(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->updates->list($this->environment($request))]);
    }

    public function storeUpdate(Request $request): JsonResponse
    {
        $this->ensureCanWrite($request);
        $validated = $request->validate($this->updateRules('required'));

        return response()->json([
            'data' => $this->updates->publish($this->tenant($request), $this->environment($request), $this->actor($request), $validated),
        ], 201);
    }

    public function updateUpdate(Request $request, string $tenantId, string $environmentId, string $updateId): JsonResponse
    {
        $this->ensureCanWrite($request);
        $validated = $request->validate($this->updateRules('sometimes'));

        return response()->json([
            'data' => $this->updates->edit($this->tenant($request), $this->environment($request), $this->actor($request), $updateId, $validated),
        ]);
    }

    public function destroyUpdate(Request $request, string $tenantId, string $environmentId, string $updateId): JsonResponse
    {
        $this->ensureCanWrite($request);
        $this->updates->delete($this->tenant($request), $this->environment($request), $this->actor($request), $updateId);

        return response()->json(null, 204);
    }

    private function updateRules(string $presence): array
    {
        return [
            'status' => [$presence, Rule::in(StatusPageUpdate::STATUSES)],
            'impact' => ['sometimes', Rule::in(StatusPageUpdate::IMPACTS)],
            'title' => [$presence, 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function present(object $page): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'visibility' => $page->visibility,
            'locale' => $page->locale,
            'timezone' => $page->timezone,
            'accent_color' => $page->accent_color,
            'website_url' => $page->website_url,
            'privacy_url' => $page->privacy_url,
            'show_powered_by' => (bool) $page->show_powered_by,
            // Email subscriptions (#92): the count only; addresses are never exposed.
            'subscriptions_enabled' => (bool) ($page->subscriptions_enabled ?? false),
            'subscriber_count' => app(\App\Application\StatusPages\Subscriptions\SubscriptionService::class)->confirmedCount((int) $page->id),
            'logo_url' => StatusPageLogoService::publicUrl($page->slug, $page->logo_path),
            'public_url' => url('/status/'.$page->slug),
            'custom_domain' => $page->custom_domain,
            'custom_url' => $page->custom_domain === null ? null : 'https://'.$page->custom_domain,
            'updated_at' => \App\Http\Support\ApiDate::format($page->updated_at),
        ];
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }

    private function environment(Request $request): Environment
    {
        return $request->attributes->get('environment');
    }
}
