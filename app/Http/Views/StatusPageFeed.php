<?php

namespace App\Http\Views;

use Carbon\Carbon;

/**
 * Builds Atom entries from a status page snapshot: operator-written public
 * updates and incidents (fixed state and times only, like the page itself).
 */
final class StatusPageFeed
{
    private const LIMIT = 50;

    public function __construct(
        private readonly array $snapshot,
        private readonly string $slug,
        private readonly StatusPageFormat $fmt,
        private readonly string $pageUrl,
    ) {
    }

    /**
     * @return list<array{id: string, title: string, updated: string, published: string, link: string, content: string}>
     */
    public function entries(): array
    {
        $entries = [];
        $page = rtrim($this->pageUrl, '/');

        foreach ($this->snapshot['update_history'] ?? [] as $update) {
            $key = $update['id'] ?? sha1(($update['published_at'] ?? '').($update['title'] ?? ''));
            $entries[] = [
                'id' => $this->tag('update/'.$key),
                'title' => sprintf('[%s] %s', $this->fmt->label('update_statuses', $update['status'] ?? null), $update['title'] ?? ''),
                'published' => $this->atom($update['published_at']),
                'updated' => $this->atom($update['edited_at'] ?? $update['published_at']),
                'link' => $page.'/history',
                'content' => (string) ($update['body'] ?? ''),
            ];
        }

        $names = [];
        foreach ($this->snapshot['components'] ?? [] as $component) {
            if (isset($component['id'])) {
                $names[$component['id']] = $component['name'];
            }
        }

        foreach ($this->snapshot['incidents'] ?? [] as $incident) {
            $affected = array_values(array_filter(array_map(static fn ($id) => $names[$id] ?? null, $incident['components'] ?? [])));
            $resolved = $incident['resolved_at'] ?? null;
            $title = __('status.states.'.$incident['state']).($affected === [] ? '' : ': '.implode(', ', $affected));
            $content = __('status.incident_started', ['at' => $this->fmt->dateTime($incident['started_at'])]);
            if ($resolved !== null) {
                $content .= ' · '.__('status.incident_resolved', ['at' => $this->fmt->dateTime($resolved)]);
            } else {
                $content .= ' · '.__('status.incident_ongoing');
            }
            $entries[] = [
                'id' => $this->tag('incident/'.sha1($incident['started_at'].'|'.implode(',', $incident['components'] ?? []))),
                'title' => $title,
                'published' => $this->atom($incident['started_at']),
                'updated' => $this->atom($resolved ?? $incident['started_at']),
                'link' => count($incident['components'] ?? []) === 1 ? $page.'/components/'.$incident['components'][0] : $page,
                'content' => $content,
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($b['updated'], $a['updated']));

        return array_slice($entries, 0, self::LIMIT);
    }

    public function updated(array $entries): string
    {
        return $entries[0]['updated'] ?? $this->atom($this->snapshot['generated_at'] ?? now()->toIso8601String());
    }

    public function feedId(): string
    {
        return $this->tag('feed');
    }

    private function tag(string $suffix): string
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'statusconnect';

        return sprintf('tag:%s,2026:status/%s/%s', $host, $this->slug, $suffix);
    }

    private function atom(string $value): string
    {
        return Carbon::parse($value)->utc()->format(DATE_ATOM);
    }
}
