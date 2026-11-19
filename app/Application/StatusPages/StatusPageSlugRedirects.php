<?php

namespace App\Application\StatusPages;

use Illuminate\Support\Facades\DB;

/**
 * Old status page addresses (#248). When a page's slug changes, the old slug keeps
 * answering with a 301 to the page's current address, through any number of renames.
 * A live page always wins: taking a slug drops the redirect that held it.
 */
final class StatusPageSlugRedirects
{
    public function record(int $statusPageId, string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }

        $now = now();
        DB::table('status_page_slug_redirects')->where('old_slug', $newSlug)->delete();
        DB::table('status_page_slug_redirects')->updateOrInsert(
            ['old_slug' => $oldSlug],
            ['status_page_id' => $statusPageId, 'updated_at' => $now, 'created_at' => $now],
        );
    }

    /** The current slug for an old one, or null. */
    public function resolve(string $slug): ?string
    {
        $current = DB::table('status_page_slug_redirects as redirect')
            ->join('status_pages as page', 'page.id', '=', 'redirect.status_page_id')
            ->where('redirect.old_slug', $slug)
            ->value('page.slug');

        return is_string($current) && $current !== $slug ? $current : null;
    }

    /** @return list<string> old slugs that point at the page */
    public function oldSlugs(int $statusPageId): array
    {
        return DB::table('status_page_slug_redirects')->where('status_page_id', $statusPageId)->orderBy('id')->pluck('old_slug')->all();
    }
}
