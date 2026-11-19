<?php

namespace App\Application\Scheduling;

use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DueMonitorClaimer
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    /**
     * Due, unclaimed monitors left over at the end of a tick, ignoring the
     * timeout filter: these wait for the next tick and are checked late.
     *
     * @param  list<int>  $excludedMonitorIds
     * @param  int|null  $tenantId  Only this organization's monitors (capacity test, #214).
     */
    public function countDue(array $excludedMonitorIds = [], ?int $tenantId = null): int
    {
        $nowFormatted = $this->clock->nowUtc()->format('Y-m-d H:i:s');

        return Monitor::query()
            ->where('enabled', true)
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', $nowFormatted)
            ->where(function ($q) use ($nowFormatted) {
                $q->whereNull('claim_token')
                    ->orWhere('claim_expires_at', '<', $nowFormatted);
            })
            ->when($excludedMonitorIds !== [], fn ($q) => $q->whereNotIn('id', $excludedMonitorIds))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when(($held = $this->heldTenantIds()) !== [], fn ($q) => $q->whereNotIn('tenant_id', $held))
            ->count();
    }

    /**
     * @param  list<int>  $excludedMonitorIds
     * @param  int|null  $tenantId  Only this organization's monitors (capacity test, #214).
     * @param  list<string>|null  $kinds  Only these monitor kinds (#221).
     * @return Collection<int, Monitor>
     */
    public function claimDueMonitors(
        int $limit = 50,
        ?int $leaseSeconds = null,
        array $excludedMonitorIds = [],
        ?int $maxTimeoutMs = null,
        ?int $tenantId = null,
        ?array $kinds = null,
    ): Collection
    {
        $now = $this->clock->nowUtc();
        $leaseSeconds ??= max(1, (int) config('scheduler.claim_ttl_minutes', 5)) * 60;
        $claimExpiresAt = $now->modify("+{$leaseSeconds} seconds");

        try {
            return DB::transaction(function () use ($now, $claimExpiresAt, $limit, $excludedMonitorIds, $maxTimeoutMs, $tenantId, $kinds) {
            $nowFormatted = $now->format('Y-m-d H:i:s');

            // Mirrors taskconnect app/Application/Scheduling/DueTaskClaimer.php.
            $query = Monitor::query()
                ->where('enabled', true)
                ->whereNotNull('next_check_at')
                ->where('next_check_at', '<=', $nowFormatted)
                ->where(function ($q) use ($nowFormatted) {
                    $q->whereNull('claim_token')
                        ->orWhere('claim_expires_at', '<', $nowFormatted);
                })
                ->orderBy('next_check_at')
                ->limit($limit);

            if ($excludedMonitorIds !== []) {
                $query->whereNotIn('id', $excludedMonitorIds);
            }

            if ($maxTimeoutMs !== null) {
                $query->where('timeout_ms', '<=', $maxTimeoutMs);
            }

            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }

            // Organizations awaiting approval or suspended by a platform admin (#218) are not checked.
            $held = $this->heldTenantIds();
            if ($held !== []) {
                $query->whereNotIn('tenant_id', $held);
            }

            if ($kinds !== null) {
                $query->whereIn('kind', $kinds);
            }

            if (DB::connection()->getDriverName() === 'mysql') {
                $query->lock('FOR UPDATE SKIP LOCKED');
            } else {
                // SQLite has no SKIP LOCKED; its transaction lock is the portable test fallback.
                $query->lockForUpdate();
            }

            $candidates = $query->get();

            $claimed = new Collection();
            foreach ($candidates as $candidate) {
                $claimToken = (string) Str::uuid();
                $nextCheckAt = $this->nextCheckAt($candidate, $now);

                $updated = Monitor::query()
                    ->whereKey($candidate->id)
                    ->where('enabled', true)
                    ->whereNotNull('next_check_at')
                    ->where('next_check_at', '<=', $nowFormatted)
                    ->where(function ($q) use ($nowFormatted) {
                        $q->whereNull('claim_token')
                            ->orWhere('claim_expires_at', '<', $nowFormatted);
                    })
                    ->when($maxTimeoutMs !== null, function ($query) use ($maxTimeoutMs) {
                        $query->where('timeout_ms', '<=', $maxTimeoutMs);
                    })
                    ->update([
                        'claim_token' => $claimToken,
                        'claimed_at' => $now->format('Y-m-d H:i:s'),
                        'claim_expires_at' => $claimExpiresAt->format('Y-m-d H:i:s'),
                        'next_check_at' => $nextCheckAt->format('Y-m-d H:i:s'),
                    ]);

                if ($updated === 0) {
                    continue;
                }

                $candidate->refresh();
                $claimed->push($candidate->load(['assertions', 'tenant', 'environment']));
            }

                return $claimed;
            });
        } catch (QueryException $exception) {
            if (DB::connection()->getDriverName() === 'sqlite'
                && str_contains(strtolower($exception->getMessage()), 'database is locked')) {
                return new Collection();
            }

            throw $exception;
        }
    }

    private function nextCheckAt(Monitor $monitor, DateTimeImmutable $now): DateTimeImmutable
    {
        $previous = $monitor->next_check_at === null
            ? $now
            : DateTimeImmutable::createFromInterface($monitor->next_check_at);
        $nextFromPreviousPhase = $previous->modify(sprintf('+%d seconds', $monitor->interval_seconds));

        return $nextFromPreviousPhase > $now ? $nextFromPreviousPhase : $now;
    }

    public function releaseClaim(Monitor $monitor): bool
    {
        if ($monitor->claim_token === null) {
            return false;
        }

        return Monitor::query()
            ->whereKey($monitor->id)
            ->where('claim_token', $monitor->claim_token)
            ->update([
                'claim_token' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
            ]) === 1;
    }

    /**
     * Organizations whose monitors are not checked: awaiting sign-up approval or
     * suspended by a platform admin (approved_at null, #120/#218). A short list,
     * read without a lock so the claim query itself stays a single-table lock.
     *
     * @return list<int>
     */
    private function heldTenantIds(): array
    {
        return \App\Infrastructure\Persistence\Eloquent\Tenant::query()->whereNull('approved_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
