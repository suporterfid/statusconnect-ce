<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Monitoring\Agent\MetricSample;
use App\Domain\Monitoring\Agent\MetricThresholdRule;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Latest-value storage for `agent` monitors (agent-ingestion addendum §6).
 * Every query is scoped by monitor id, and writes also carry the monitor's
 * tenant id, so one tenant's agent can never read or overwrite another's.
 */
final class AgentMetricRepository
{
    public function __construct(private readonly int $maxMetricsPerMonitor = 200)
    {
    }

    public static function hash(string $metricKey): string
    {
        return hash('sha256', $metricKey);
    }

    /**
     * Upserts the samples as the monitor's latest values and stamps
     * last_ingest_at. New metric keys beyond the per-monitor cap are dropped.
     *
     * @param  list<MetricSample>  $samples
     */
    public function store(Monitor $monitor, array $samples, DateTimeImmutable $receivedAt): StoredMetrics
    {
        $received = $receivedAt->format('Y-m-d H:i:s');
        $byHash = [];
        foreach ($samples as $sample) {
            $byHash[self::hash($sample->key)] = $sample;
        }

        $known = $byHash === [] ? [] : DB::table('agent_metric_latest')
            ->where('monitor_id', $monitor->id)
            ->whereIn('metric_key_hash', array_keys($byHash))
            ->pluck('metric_key_hash')
            ->flip()
            ->all();
        $room = max(0, $this->maxMetricsPerMonitor - DB::table('agent_metric_latest')->where('monitor_id', $monitor->id)->count());

        $rows = [];
        $dropped = 0;
        foreach ($byHash as $hash => $sample) {
            if (! isset($known[$hash])) {
                if ($room === 0) {
                    $dropped++;

                    continue;
                }
                $room--;
            }

            $rows[] = [
                'tenant_id' => $monitor->tenant_id,
                'monitor_id' => $monitor->id,
                'metric_key_hash' => $hash,
                'metric_key' => $sample->key,
                'value' => $sample->value,
                'sampled_at' => $this->sampledAt($sample, $receivedAt),
                'received_at' => $received,
            ];
        }

        DB::transaction(function () use ($rows, $monitor, $received): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('agent_metric_latest')->upsert($chunk, ['monitor_id', 'metric_key_hash'], ['metric_key', 'value', 'sampled_at', 'received_at']);
            }
            DB::table('monitors')->where('id', $monitor->id)->update(['last_ingest_at' => $received]);
        });

        return new StoredMetrics(count($rows), $dropped);
    }

    /**
     * @return array<string, array{value: float, received_at: DateTimeImmutable}>
     */
    public function latest(Monitor $monitor): array
    {
        $out = [];
        foreach (DB::table('agent_metric_latest')->where('monitor_id', $monitor->id)->orderBy('metric_key')->get(['metric_key', 'value', 'received_at']) as $row) {
            $out[$row->metric_key] = ['value' => (float) $row->value, 'received_at' => new DateTimeImmutable((string) $row->received_at)];
        }

        return $out;
    }

    /**
     * @return list<MetricThresholdRule>
     */
    public function rules(Monitor $monitor): array
    {
        return AgentMetricRule::query()
            ->where('monitor_id', $monitor->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (AgentMetricRule $rule): MetricThresholdRule => $rule->toDomain())
            ->all();
    }

    /**
     * Deletes up to $limit metrics not updated for more than $days days.
     */
    public function deleteStale(int $days, int $limit): int
    {
        $cutoff = now()->subDays($days)->format('Y-m-d H:i:s');
        $ids = DB::table('agent_metric_latest')->where('received_at', '<', $cutoff)->orderBy('id')->limit($limit)->pluck('id')->all();

        return $ids === [] ? 0 : DB::table('agent_metric_latest')->whereIn('id', $ids)->delete();
    }

    private function sampledAt(MetricSample $sample, DateTimeImmutable $receivedAt): string
    {
        if ($sample->timestampNs === null) {
            return $receivedAt->format('Y-m-d H:i:s');
        }

        $seconds = intdiv($sample->timestampNs, 1_000_000_000);
        // A clock far off (or a non-nanosecond precision) falls back to the receive time.
        if (abs($seconds - $receivedAt->getTimestamp()) > 86400 * 365) {
            return $receivedAt->format('Y-m-d H:i:s');
        }

        return gmdate('Y-m-d H:i:s', $seconds);
    }
}
