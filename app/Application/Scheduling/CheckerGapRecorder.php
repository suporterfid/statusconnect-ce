<?php

namespace App\Application\Scheduling;

use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\Eloquent\SystemHeartbeat;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Mail\AccountMail;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notices when the checker did not run for a while (#199).
 *
 * The checker cannot report its own absence. What it can do is notice, on the
 * first tick after it comes back, that the previous tick is too old. That
 * period is stored as a gap (shown as "no data" on status pages) and the
 * platform admins are emailed. Watching it while it happens needs a monitor
 * outside this server: GET /v1/platform/health?checker=1.
 */
final class CheckerGapRecorder
{
    public const HEARTBEAT = 'checker';

    /** Gaps older than this are deleted when a new one is recorded. */
    private const KEEP_DAYS = 100;

    public function __construct(private readonly Clock $clock)
    {
    }

    public static function thresholdMinutes(): int
    {
        return max(2, (int) config('scheduler.gap_minutes', 3));
    }

    /** Last tick of the checker, or null when it never ran. */
    public static function lastTickAt(): ?DateTimeImmutable
    {
        $at = SystemHeartbeat::query()->where('name', self::HEARTBEAT)->value('last_seen_at');

        return $at === null ? null : new DateTimeImmutable((string) $at, new \DateTimeZone('UTC'));
    }

    /** Called at the start of a tick, before the heartbeat is updated. */
    public function beforeTick(): void
    {
        $last = self::lastTickAt();
        $now = $this->clock->nowUtc();
        if ($last === null || $now->getTimestamp() - $last->getTimestamp() < self::thresholdMinutes() * 60) {
            return;
        }

        DB::table('checker_gaps')->insert([
            'started_at' => $last->format('Y-m-d H:i:s'),
            'ended_at' => $now->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
        DB::table('checker_gaps')->where('ended_at', '<', $now->modify('-'.self::KEEP_DAYS.' days')->format('Y-m-d H:i:s'))->delete();

        $minutes = intdiv($now->getTimestamp() - $last->getTimestamp(), 60);
        Log::warning('The checker did not run for a while; the gap is shown as no data.', [
            'from' => $last->format(DATE_ATOM), 'to' => $now->format(DATE_ATOM), 'minutes' => $minutes,
        ]);

        foreach (User::query()->where('is_platform_admin', true)->get() as $admin) {
            try {
                Mail::to($admin->email)->send(new AccountMail('admin_checker_gap', __('messages.mail_checker_gap_subject', ['minutes' => $minutes]), [
                    'from' => $last->format('Y-m-d H:i').' UTC',
                    'to' => $now->format('Y-m-d H:i').' UTC',
                    'minutes' => $minutes,
                ]));
            } catch (Throwable $e) {
                Log::error('Could not email the checker gap alert.', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Seconds of gap inside each UTC day of [$from, $to), keyed by Y-m-d.
     *
     * @return array<string, int>
     */
    public static function secondsByDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $out = [];
        $gaps = DB::table('checker_gaps')
            ->where('ended_at', '>', $from->format('Y-m-d H:i:s'))
            ->where('started_at', '<', $to->format('Y-m-d H:i:s'))
            ->get(['started_at', 'ended_at']);
        $utc = new \DateTimeZone('UTC');
        foreach ($gaps as $gap) {
            $start = max(new DateTimeImmutable((string) $gap->started_at, $utc), $from);
            $end = min(new DateTimeImmutable((string) $gap->ended_at, $utc), $to);
            while ($start < $end) {
                $dayEnd = $start->setTime(0, 0)->modify('+1 day');
                $sliceEnd = min($dayEnd, $end);
                $key = $start->format('Y-m-d');
                $out[$key] = ($out[$key] ?? 0) + ($sliceEnd->getTimestamp() - $start->getTimestamp());
                $start = $sliceEnd;
            }
        }

        return $out;
    }
}
