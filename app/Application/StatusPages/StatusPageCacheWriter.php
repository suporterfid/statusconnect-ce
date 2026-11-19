<?php

namespace App\Application\StatusPages;

use App\Domain\StatusPage\AccentColor;
use App\Domain\StatusPage\StatusPageLocale;
use App\Domain\Shared\Clock;
use Illuminate\Support\Facades\DB;

final class StatusPageCacheWriter
{
    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * Rebuild only pages that have no snapshot or whose selected monitor/component changed.
     */
    public function refreshStalePages(): int
    {
        $refreshed = 0;
        foreach (DB::table('status_pages')->orderBy('id')->get() as $page) {
            $cache = DB::table('status_page_cache')->where('status_page_id', $page->id)->first();
            if (! $this->needsRefresh($page, $cache)) {
                continue;
            }

            $payload = $this->payloadFor($page);
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
            $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');

            DB::table('status_page_cache')->updateOrInsert(
                ['status_page_id' => $page->id],
                [
                    'payload_json' => $payloadJson,
                    'etag' => hash('sha256', $payloadJson),
                    'generated_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
            $refreshed++;
        }

        return $refreshed;
    }

    private function needsRefresh(object $page, ?object $cache): bool
    {
        if ($cache === null || $page->updated_at > $cache->generated_at) {
            return true;
        }

        return DB::table('status_page_components as component')
            ->leftJoin('status_page_component_monitors as component_monitor', 'component_monitor.status_page_component_id', '=', 'component.id')
            ->leftJoin('monitors as monitor', function ($join) use ($page): void {
                $join->on('monitor.id', '=', 'component_monitor.monitor_id')
                    ->where('monitor.environment_id', $page->environment_id);
            })
            ->where('component.status_page_id', $page->id)
            ->where(function ($query) use ($cache): void {
                $query->where('component.updated_at', '>', $cache->generated_at)
                    ->orWhere('component_monitor.updated_at', '>', $cache->generated_at)
                    ->orWhere('monitor.updated_at', '>', $cache->generated_at);
            })
            ->exists();
    }

    private function payloadFor(object $page): array
    {
        $now = $this->clock->nowUtc();
        $components = DB::table('status_page_components as component')
            ->leftJoin('status_page_component_monitors as component_monitor', 'component_monitor.status_page_component_id', '=', 'component.id')
            ->leftJoin('monitors as monitor', function ($join) use ($page): void {
                $join->on('monitor.id', '=', 'component_monitor.monitor_id')
                    ->where('monitor.public_safe', true)
                    ->where('monitor.environment_id', $page->environment_id);
            })
            ->where('component.status_page_id', $page->id)
            ->orderBy('component.sort_order')
            ->orderBy('component.id')
            ->get([
                'component.id as component_id',
                'component.name as component_name',
                'monitor.id as monitor_id',
                'monitor.current_state',
            ]);

        $monitorIds = $components->pluck('monitor_id')->filter()->map(static fn ($id): int => (int) $id)->all();
        $rollups = $monitorIds === [] ? collect() : DB::table('check_rollups')
            ->where('bucket_kind', 'day')
            ->whereIn('monitor_id', $monitorIds)
            ->where('bucket_start', '>=', $now->modify('-90 days')->format('Y-m-d H:i:s'))
            ->get();

        // The current day has no closed daily rollup yet. Aggregate its partial bucket here;
        // the public request still reads only the resulting status_page_cache snapshot.
        $partial = $monitorIds === [] ? collect() : DB::table('check_results')
            ->whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $now->setTime(0, 0)->format('Y-m-d H:i:s'))
            ->whereNull('voided_at')
            ->selectRaw('monitor_id, state, failure_confirmed, count(*) as checks_total')
            ->groupBy('monitor_id', 'state', 'failure_confirmed')
            ->get()
            ->groupBy('monitor_id')
            ->map(function ($results) use ($now): object {
                // #237: an unconfirmed failure counts as up.
                $counts = [];
                foreach ($results as $row) {
                    $state = \App\Application\Rollups\AvailabilityState::of((string) $row->state, $row->failure_confirmed);
                    $counts[$state] = ($counts[$state] ?? 0) + (int) $row->checks_total;
                }

                return (object) [
                    'monitor_id' => $results->first()->monitor_id,
                    'bucket_start' => $now->setTime(0, 0)->format('Y-m-d H:i:s'),
                    'up_count' => $counts['up'] ?? 0,
                    'degraded_count' => $counts['degraded'] ?? 0,
                    'down_count' => $counts['down'] ?? 0,
                    'blocked_count' => $counts['blocked'] ?? 0,
                    'skipped_count' => $counts['skipped'] ?? 0,
                    'no_data_count' => $counts['no_data'] ?? 0,
                ];
            });

        $byMonitor = $rollups->concat($partial)->groupBy('monitor_id');
        $hourly = $monitorIds === [] ? collect() : DB::table('check_rollups')
            ->where('bucket_kind', 'hour')
            ->whereIn('monitor_id', $monitorIds)
            ->where('bucket_start', '>=', $now->setTime((int) $now->format('H'), 0)->modify('-7 days')->format('Y-m-d H:i:s'))
            ->whereNotNull('latency_avg_ms')
            ->get(['monitor_id', 'bucket_start', 'checks_total', 'latency_min_ms', 'latency_avg_ms', 'latency_p95_ms', 'latency_max_ms'])
            ->groupBy('monitor_id');
        $byComponent = $components->groupBy('component_id');
        $serializedComponents = $byComponent->map(function ($rows) use ($byMonitor, $hourly): array {
            $monitors = $rows->filter(static fn (object $row): bool => $row->monitor_id !== null);
            $componentHourly = $monitors->flatMap(static fn (object $row) => $hourly->get($row->monitor_id, collect()));

            return [
                'id' => (int) $rows->first()->component_id,
                'name' => $rows->first()->component_name,
                'state' => $this->publicState($monitors->pluck('current_state')->all()),
                'uptime' => $this->uptime($monitors, $byMonitor),
                'history' => $this->history($monitors, $byMonitor),
                'response_time' => [
                    '24h' => $this->responseTime($componentHourly, 24, 1),
                    '7d' => $this->responseTime($componentHourly, 42, 4),
                ],
                // Main page figure (#242): average over the 24 h series' window; null = no data.
                'latency_24h_ms' => $this->averageLatency($componentHourly, 24),
            ];
        })->values()->all();

        $componentsByMonitor = $components
            ->filter(static fn (object $row): bool => $row->monitor_id !== null)
            ->groupBy('monitor_id')
            ->map(static fn ($rows) => $rows->pluck('component_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all());

        $allMonitors = $components
            ->filter(static fn (object $row): bool => $row->monitor_id !== null)
            ->unique('monitor_id');

        return [
            'title' => $page->title,
            'branding' => $this->brandingFor($page),
            'locale' => StatusPageLocale::normalize($page->locale),
            'timezone' => $page->timezone,
            'overall_state' => $this->publicState(array_column($serializedComponents, 'state')),
            'overall_uptime' => $this->uptime($allMonitors, $byMonitor),
            'components' => $serializedComponents,
            'maintenance' => $this->maintenanceFor((int) $page->environment_id),
            'incidents' => $this->incidentsFor($monitorIds, $componentsByMonitor),
            'updates' => $this->updatesFor((int) $page->id, 30),
            'update_history' => $this->updatesFor((int) $page->id, null),
            // Email subscriptions (#92): private pages never offer the form.
            'subscriptions' => (bool) ($page->subscriptions_enabled ?? false) && $page->visibility !== 'private',
            'generated_at' => $this->clock->nowUtc()->format(DATE_ATOM),
        ];
    }

    private function brandingFor(object $page): array
    {
        $accent = AccentColor::normalize($page->accent_color ?? null);

        return [
            'logo_url' => StatusPageLogoService::publicUrl($page->slug, $page->logo_path ?? null),
            'accent_color' => $accent,
            'accent_text' => $accent === null ? null : AccentColor::textColorFor($accent),
            'website_url' => $page->website_url ?? null,
            'privacy_url' => $page->privacy_url ?? null,
            'show_powered_by' => (bool) ($page->show_powered_by ?? true),
        ];
    }

    private function maintenanceFor(int $environmentId): array
    {
        $now = $this->clock->nowUtc()->format('Y-m-d H:i:s');

        return DB::table('maintenance_windows')
            ->where('environment_id', $environmentId)
            ->where(function ($q) use ($now): void {
                $q->where('ends_at', '>=', $now)
                    ->orWhere('recurring_weekly', true);
            })
            ->orderBy('starts_at', 'asc')
            ->get(['name', 'description', 'starts_at', 'ends_at', 'recurring_weekly'])
            ->map(fn (object $mw): array => [
                'name' => $mw->name,
                'description' => $mw->description,
                'starts_at' => $mw->starts_at,
                'ends_at' => $mw->ends_at,
                'recurring_weekly' => (bool) $mw->recurring_weekly,
            ])
            ->all();
    }

    private function uptime($monitors, $byMonitor): array
    {
        $periods = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90];
        $result = [];
        foreach ($periods as $label => $days) {
            $cutoff = $this->clock->nowUtc()->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s');
            $counts = ['up' => 0, 'degraded' => 0, 'down' => 0];
            foreach ($monitors as $monitor) {
                foreach (($byMonitor[$monitor->monitor_id] ?? collect())->filter(static fn (object $rollup): bool => $rollup->bucket_start >= $cutoff) as $rollup) {
                    foreach (array_keys($counts) as $state) {
                        $counts[$state] += (int) $rollup->{$state . '_count'};
                    }
                }
            }
            $denominator = array_sum($counts);
            $result[$label] = $denominator === 0 ? 0.0 : round((($counts['up'] + $counts['degraded']) / $denominator) * 100, 2);
        }

        return $result;
    }

    /**
     * One entry per UTC day for the last 90 days. `uptime` is null on days without
     * up/degraded/down checks; `downtime_seconds` comes from closed daily rollups
     * (the partial current day has none yet, so it reports 0). `no_data_seconds`
     * is the time the checker did not run that day (#199); uptime covers only
     * the checked time, so a gap neither counts as up nor as down.
     */
    private function history($monitors, $byMonitor): array
    {
        $history = [];
        // Checker gaps (#199): time in which nothing was checked at all.
        $gaps = \App\Application\Scheduling\CheckerGapRecorder::secondsByDay(
            $this->clock->nowUtc()->modify('-89 days')->setTime(0, 0),
            $this->clock->nowUtc(),
        );
        for ($offset = 89; $offset >= 0; $offset--) {
            $date = $this->clock->nowUtc()->modify(sprintf('-%d days', $offset))->format('Y-m-d');
            $states = [];
            $counts = ['up' => 0, 'degraded' => 0, 'down' => 0];
            $downtime = 0;
            foreach ($monitors as $monitor) {
                $rollup = ($byMonitor[$monitor->monitor_id] ?? collect())->first(
                    static fn (object $row): bool => substr($row->bucket_start, 0, 10) === $date,
                );
                if ($rollup === null) {
                    continue;
                }
                $states[] = (int) $rollup->down_count > 0 ? 'down' : ((int) $rollup->degraded_count > 0 ? 'degraded' : 'up');
                foreach (array_keys($counts) as $state) {
                    $counts[$state] += (int) $rollup->{$state . '_count'};
                }
                $downtime += (int) ($rollup->downtime_seconds ?? 0);
            }
            $denominator = array_sum($counts);
            $history[] = [
                'date' => $date,
                'state' => $this->publicState($states),
                'uptime' => $denominator === 0 ? null : round((($counts['up'] + $counts['degraded']) / $denominator) * 100, 2),
                'downtime_seconds' => $downtime,
                // Only on days with checks: before a component existed every day is no data anyway.
                'no_data_seconds' => $denominator === 0 ? 0 : ($gaps[$date] ?? 0),
            ];
        }

        return $history;
    }

    /**
     * Operator-written public updates. With $days, only those published in that
     * window (for the main page); without, the latest 100 (for /history).
     */
    private function updatesFor(int $pageId, ?int $days): array
    {
        $now = $this->clock->nowUtc();

        return DB::table('status_page_updates')
            ->where('status_page_id', $pageId)
            ->where('published_at', '<=', $now->format('Y-m-d H:i:s'))
            ->when($days !== null, fn ($q) => $q->where('published_at', '>=', $now->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s')))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['public_id', 'status', 'impact', 'title', 'body', 'published_at', 'updated_at'])
            ->map(static fn (object $update): array => [
                'id' => $update->public_id,
                'status' => $update->status,
                'impact' => $update->impact,
                'title' => $update->title,
                'body' => $update->body,
                'published_at' => $update->published_at,
                'edited_at' => strtotime((string) $update->updated_at) - strtotime((string) $update->published_at) > 60 ? $update->updated_at : null,
            ])
            ->all();
    }

    /**
     * Latency series from closed hourly rollups: $points buckets of $hoursPerPoint
     * hours each, ending at the last closed hour. Each bucket averages the
     * monitors' hourly averages weighted by check count; p95 and max take the
     * worst monitor. Buckets without data are null so charts can show gaps.
     *
     * @return list<array{t: string, avg_ms: int|null, p95_ms: int|null, min_ms: int|null, max_ms: int|null}>
     */
    private function responseTime($rows, int $points, int $hoursPerPoint): array
    {
        $now = $this->clock->nowUtc();
        $end = $now->setTime((int) $now->format('H'), 0);
        $start = $end->modify(sprintf('-%d hours', $points * $hoursPerPoint));
        $buckets = array_fill(0, $points, []);
        foreach ($rows as $row) {
            $offset = intdiv(strtotime((string) $row->bucket_start) - $start->getTimestamp(), 3600);
            if ($offset < 0 || $offset >= $points * $hoursPerPoint) {
                continue;
            }
            $buckets[intdiv($offset, $hoursPerPoint)][] = $row;
        }

        $series = [];
        foreach ($buckets as $index => $bucket) {
            $weight = array_sum(array_map(static fn (object $r): int => max(1, (int) $r->checks_total), $bucket));
            $series[] = [
                't' => $start->modify(sprintf('+%d hours', $index * $hoursPerPoint))->format(DATE_ATOM),
                'avg_ms' => $bucket === [] ? null : (int) round(array_sum(array_map(static fn (object $r): float => (int) $r->latency_avg_ms * max(1, (int) $r->checks_total), $bucket)) / $weight),
                'p95_ms' => $bucket === [] ? null : max(array_map(static fn (object $r): int => (int) ($r->latency_p95_ms ?? $r->latency_avg_ms), $bucket)),
                'min_ms' => $bucket === [] ? null : min(array_map(static fn (object $r): int => (int) ($r->latency_min_ms ?? $r->latency_avg_ms), $bucket)),
                'max_ms' => $bucket === [] ? null : max(array_map(static fn (object $r): int => (int) ($r->latency_max_ms ?? $r->latency_avg_ms), $bucket)),
            ];
        }

        return $series;
    }

    /**
     * Average latency over the last $hours closed hours, weighted by check
     * count like the chart buckets: the same rows as the 24 h series,
     * averaged as one bucket. Null when there is no data.
     */
    private function averageLatency($rows, int $hours): ?int
    {
        $now = $this->clock->nowUtc();
        $end = $now->setTime((int) $now->format('H'), 0)->getTimestamp();
        $start = $end - $hours * 3600;
        $sum = 0.0;
        $weight = 0;
        foreach ($rows as $row) {
            $at = strtotime((string) $row->bucket_start);
            if ($at < $start || $at >= $end) {
                continue;
            }
            $checks = max(1, (int) $row->checks_total);
            $sum += (int) $row->latency_avg_ms * $checks;
            $weight += $checks;
        }

        return $weight === 0 ? null : (int) round($sum / $weight);
    }

    private function incidentsFor(array $monitorIds, $componentsByMonitor): array
    {
        if ($monitorIds === []) {
            return [];
        }

        return DB::table('incidents')
            ->whereIn('monitor_id', $monitorIds)
            ->orderByDesc('started_at')
            ->limit(100)
            ->get(['id', 'monitor_id', 'started_at', 'resolved_at'])
            ->map(function (object $incident) use ($componentsByMonitor): array {
                return [
                    'state' => 'major_outage',
                    'components' => $componentsByMonitor->get($incident->monitor_id, []),
                    'started_at' => $incident->started_at,
                    'resolved_at' => $incident->resolved_at,
                    // Operator-entered update messages can contain target details; publish only fixed status and time.
                    'updates' => DB::table('incident_updates')
                        ->where('incident_id', $incident->id)
                        ->orderBy('published_at')
                        ->get(['status', 'published_at'])
                        ->map(static fn (object $update): array => [
                            'status' => $update->status,
                            'published_at' => $update->published_at,
                        ])
                        ->all(),
                ];
            })
            ->all();
    }

    private function publicState(array $states): string
    {
        if (in_array('down', $states, true) || in_array('major_outage', $states, true)) {
            return 'major_outage';
        }
        if (in_array('degraded', $states, true)) {
            return 'degraded';
        }
        if (in_array('up', $states, true) || in_array('operational', $states, true)) {
            return 'operational';
        }

        return 'no_data';
    }
}
