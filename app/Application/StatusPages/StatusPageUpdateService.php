<?php

namespace App\Application\StatusPages;

use App\Application\Tenancy\AuditRecorder;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\StatusPageUpdate;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\DB;

/**
 * Operator-written public announcements for an environment's status page.
 *
 * Every write re-renders the page snapshot so the public page, which only reads
 * status_page_cache, shows the change on the next request.
 */
final class StatusPageUpdateService
{
    public function __construct(
        private readonly StatusPageService $pages,
        private readonly AuditRecorder $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Environment $environment, int $limit = 100): array
    {
        $page = $this->pages->find($environment);
        if ($page === null) {
            return [];
        }

        return StatusPageUpdate::query()
            ->where('status_page_id', $page->id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (StatusPageUpdate $update): array => $this->present($update))
            ->all();
    }

    /**
     * @param  array{status: string, impact?: string, title: string, body?: string|null}  $data
     */
    public function publish(Tenant $tenant, Environment $environment, ?User $actor, array $data): array
    {
        $page = $this->requirePage($environment);

        $update = DB::transaction(function () use ($page, $actor, $data): StatusPageUpdate {
            $update = StatusPageUpdate::query()->create([
                'status_page_id' => $page->id,
                'author_user_id' => $actor?->id,
                'status' => $data['status'],
                'impact' => $data['impact'] ?? 'none',
                'title' => $data['title'],
                'body' => $data['body'] ?? null,
                'published_at' => now(),
            ]);
            $this->touch($page->id);

            return $update;
        });

        $this->audit->record($actor, 'status_update.published', 'status_page_update', $update->public_id, $tenant, [
            'status' => $update->status,
            'impact' => $update->impact,
        ]);
        $this->pages->rerender($page->id);

        return $this->present($update);
    }

    /**
     * @param  array{status?: string, impact?: string, title?: string, body?: string|null}  $data
     */
    public function edit(Tenant $tenant, Environment $environment, ?User $actor, string $publicId, array $data): array
    {
        $page = $this->requirePage($environment);
        $update = $this->requireUpdate($page->id, $publicId);

        DB::transaction(function () use ($page, $update, $data): void {
            $update->fill(array_intersect_key($data, array_flip(['status', 'impact', 'title', 'body'])))->save();
            $this->touch($page->id);
        });

        $this->audit->record($actor, 'status_update.edited', 'status_page_update', $update->public_id, $tenant, [
            'fields' => array_values(array_intersect(array_keys($data), ['status', 'impact', 'title', 'body'])),
        ]);
        $this->pages->rerender($page->id);

        return $this->present($update->refresh());
    }

    public function delete(Tenant $tenant, Environment $environment, ?User $actor, string $publicId): void
    {
        $page = $this->requirePage($environment);
        $update = $this->requireUpdate($page->id, $publicId);

        DB::transaction(function () use ($page, $update): void {
            $update->delete();
            $this->touch($page->id);
        });

        $this->audit->record($actor, 'status_update.deleted', 'status_page_update', $publicId, $tenant);
        $this->pages->rerender($page->id);
    }

    private function present(StatusPageUpdate $update): array
    {
        return [
            'id' => $update->public_id,
            'status' => $update->status,
            'impact' => $update->impact,
            'title' => $update->title,
            'body' => $update->body,
            'published_at' => \App\Http\Support\ApiDate::format($update->published_at),
            'updated_at' => \App\Http\Support\ApiDate::format($update->updated_at),
        ];
    }

    private function requirePage(Environment $environment): object
    {
        $page = $this->pages->find($environment);
        abort_if($page === null, 404);

        return $page;
    }

    private function requireUpdate(int $pageId, string $publicId): StatusPageUpdate
    {
        return StatusPageUpdate::query()
            ->where('status_page_id', $pageId)
            ->where('public_id', $publicId)
            ->firstOr(fn () => abort(404));
    }

    private function touch(int $pageId): void
    {
        DB::table('status_pages')->where('id', $pageId)->update(['updated_at' => now()]);
    }
}
