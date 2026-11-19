<?php

namespace App\Application\StatusPages\Subscriptions;

use App\Mail\StatusPageSubscriberMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Minute-cron side of status page subscriptions (#92), run by monitor:maintenance.
 *
 * collect() turns what happened since the page's last check into notifications:
 * incidents opened and resolved on the page's public monitors, and operator
 * updates published. A page seen for the first time only starts its clock, so
 * enabling subscriptions never mails the past. deliver() sends them, a bounded
 * number of emails per run, resuming from a per-notification cursor.
 */
final class SubscriberNotifier
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    /** @return array{notifications_created: int, subscriber_emails_sent: int, unconfirmed_purged: int} */
    public function run(): array
    {
        $created = $this->collect();
        $sent = $this->deliver((int) config('status_page.subscriptions.mail_per_minute', 20));
        $purged = $this->subscriptions->purgeUnconfirmed();

        return ['notifications_created' => $created, 'subscriber_emails_sent' => $sent, 'unconfirmed_purged' => $purged];
    }

    public function collect(): int
    {
        $created = 0;
        // A few seconds behind the clock, so rows written by a transaction still open now land in the next window.
        $now = CarbonImmutable::now()->utc()->subSeconds(5)->startOfSecond();
        $pages = DB::table('status_pages')->where('subscriptions_enabled', true)->whereIn('visibility', ['public', 'unlisted'])->get();

        foreach ($pages as $page) {
            if ($page->subscriptions_checked_at === null) {
                DB::table('status_pages')->where('id', $page->id)->update(['subscriptions_checked_at' => $now]);

                continue;
            }

            $from = CarbonImmutable::parse($page->subscriptions_checked_at, 'UTC');
            foreach ($this->events($page, $from, $now) as $event) {
                $created += DB::table('status_page_notifications')->insertOrIgnore([
                    'status_page_id' => $page->id,
                    'reference' => $event['reference'],
                    'kind' => $event['kind'],
                    'payload' => json_encode($event['payload'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('status_pages')->where('id', $page->id)->update(['subscriptions_checked_at' => $now]);
        }

        return $created;
    }

    public function deliver(int $budget): int
    {
        $sent = 0;
        $notifications = DB::table('status_page_notifications')->whereNull('completed_at')->orderBy('id')->limit(50)->get();

        foreach ($notifications as $notification) {
            if ($sent >= $budget) {
                break;
            }

            $page = DB::table('status_pages')->find($notification->status_page_id);
            $subscribers = DB::table('status_page_subscribers')
                ->where('status_page_id', $notification->status_page_id)
                ->whereNotNull('confirmed_at')
                // Someone who subscribed after the event does not get it.
                ->where('confirmed_at', '<=', $notification->created_at)
                ->where('id', '>', $notification->cursor_subscriber_id)
                ->orderBy('id')
                ->limit($budget - $sent)
                ->get();

            if ($subscribers->isEmpty() || $page === null) {
                DB::table('status_page_notifications')->where('id', $notification->id)->update(['completed_at' => now(), 'updated_at' => now()]);

                continue;
            }

            $payload = json_decode($notification->payload, true, flags: JSON_THROW_ON_ERROR);
            foreach ($subscribers as $subscriber) {
                try {
                    $this->subscriptions->withPageLocale($page, fn () => Mail::to($subscriber->email)->send($this->mail($page, $subscriber, $notification->kind, $payload)));
                } catch (Throwable $e) {
                    Log::warning('status_page.notification_failed', ['notification' => $notification->id, 'error' => $e->getMessage()]);
                    // Usually the SMTP server is down: stop and retry next minute. After five
                    // failed runs on the same subscriber, skip that subscriber so others still get it.
                    $failures = $notification->failures + 1;
                    DB::table('status_page_notifications')->where('id', $notification->id)->update($failures >= 5
                        ? ['failures' => 0, 'cursor_subscriber_id' => $subscriber->id, 'updated_at' => now()]
                        : ['failures' => $failures, 'updated_at' => now()]);

                    return $sent;
                }

                $sent++;
                DB::table('status_page_notifications')->where('id', $notification->id)->update(['cursor_subscriber_id' => $subscriber->id, 'failures' => 0, 'updated_at' => now()]);
                $notification->cursor_subscriber_id = $subscriber->id;
                $notification->failures = 0;
            }
        }

        return $sent;
    }

    /**
     * @return list<array{reference: string, kind: string, payload: array<string, mixed>}>
     */
    private function events(object $page, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $window = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
        $events = [];

        // Monitors shown on the page, as in the public snapshot: public_safe and in the page's environment.
        $components = DB::table('status_page_components as component')
            ->join('status_page_component_monitors as link', 'link.status_page_component_id', '=', 'component.id')
            ->join('monitors as monitor', 'monitor.id', '=', 'link.monitor_id')
            ->where('component.status_page_id', $page->id)
            ->where('monitor.public_safe', true)
            ->where('monitor.environment_id', $page->environment_id)
            ->get(['component.name', 'monitor.id as monitor_id'])
            ->groupBy('monitor_id')
            ->map(fn ($rows) => $rows->pluck('name')->unique()->values()->all());

        if ($components->isNotEmpty()) {
            $incidents = DB::table('incidents')
                ->whereIn('monitor_id', $components->keys()->all())
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('created_at', '>', $window[0])->where('created_at', '<=', $window[1]))
                    ->orWhere(fn ($w) => $w->where('resolved_at', '>', $window[0])->where('resolved_at', '<=', $window[1])))
                ->orderBy('id')
                ->get(['id', 'monitor_id', 'started_at', 'resolved_at', 'created_at']);

            foreach ($incidents as $incident) {
                $payload = [
                    'components' => $components->get($incident->monitor_id, []),
                    'started_at' => $incident->started_at,
                    'resolved_at' => $incident->resolved_at,
                ];
                if ($incident->created_at > $window[0] && $incident->created_at <= $window[1]) {
                    $events[] = ['reference' => 'incident_opened:'.$incident->id, 'kind' => 'incident_opened', 'payload' => $payload];
                }
                if ($incident->resolved_at !== null && $incident->resolved_at > $window[0] && $incident->resolved_at <= $window[1]) {
                    $events[] = ['reference' => 'incident_resolved:'.$incident->id, 'kind' => 'incident_resolved', 'payload' => $payload];
                }
            }
        }

        $updates = DB::table('status_page_updates')
            ->where('status_page_id', $page->id)
            ->where('published_at', '>', $window[0])
            ->where('published_at', '<=', $window[1])
            ->orderBy('published_at')
            ->get(['id', 'status', 'impact', 'title', 'body', 'published_at']);
        foreach ($updates as $update) {
            $events[] = ['reference' => 'update:'.$update->id, 'kind' => 'update', 'payload' => [
                'status' => $update->status,
                'impact' => $update->impact,
                'title' => $update->title,
                'body' => $update->body,
                'published_at' => $update->published_at,
            ]];
        }

        return $events;
    }

    /** @param array<string, mixed> $payload */
    private function mail(object $page, object $subscriber, string $kind, array $payload): StatusPageSubscriberMail
    {
        $tz = $page->timezone ?: 'UTC';
        $at = static fn (?string $utc): ?string => $utc === null ? null : CarbonImmutable::parse($utc, 'UTC')->setTimezone($tz)->format('d/m/Y H:i');
        $components = implode(', ', $payload['components'] ?? []);

        $subject = match ($kind) {
            'incident_opened' => __('status.subscribe_mail_incident_opened_subject', ['page' => $page->title, 'components' => $components]),
            'incident_resolved' => __('status.subscribe_mail_incident_resolved_subject', ['page' => $page->title, 'components' => $components]),
            default => __('status.subscribe_mail_update_subject', ['page' => $page->title, 'title' => $payload['title'] ?? '']),
        };

        $duration = null;
        if (! empty($payload['started_at']) && ! empty($payload['resolved_at'])) {
            $minutes = max(1, (int) round((strtotime($payload['resolved_at']) - strtotime($payload['started_at'])) / 60));
            $duration = $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h '.($minutes % 60).' min';
        }

        return new StatusPageSubscriberMail($kind === 'update' ? 'update' : 'incident', $subject, [
            'incidentKind' => $kind,
            'pageTitle' => $page->title,
            'pageUrl' => $page->custom_domain ? 'https://'.$page->custom_domain : url('/status/'.$page->slug),
            'components' => $components,
            'startedAt' => $at($payload['started_at'] ?? null),
            'resolvedAt' => $at($payload['resolved_at'] ?? null),
            'publishedAt' => $at($payload['published_at'] ?? null),
            'duration' => $duration,
            'timezone' => $tz,
            'title' => $payload['title'] ?? null,
            'body' => $payload['body'] ?? null,
            'status' => $payload['status'] ?? null,
        ] + StatusPageSubscriberMail::branding($page), $this->subscriptions->unsubscribeUrl($page, $subscriber));
    }
}
