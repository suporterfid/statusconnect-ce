<?php

namespace App\Application\Notifications\Services;

use App\Application\Notifications\NotificationTransports;
use App\Domain\Secrets\SecretRedactor;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Infrastructure\Persistence\Eloquent\NotificationDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final class NotificationDispatcherService
{
    public function __construct(
        private readonly NotificationTransports $transports,
        private readonly SecretRedactor $redactor,
    ) {
    }

    public function dispatchForIncident(Incident $incident, string $event): void
    {
        $channels = NotificationChannel::query()
            ->where('environment_id', $incident->environment_id)
            ->where('enabled', true)
            ->whereNull('archived_at')
            ->get();

        foreach ($channels as $channel) {
            $this->dispatchToChannel($incident, $channel, $event);
        }
    }

    public function dispatchToChannel(Incident $incident, NotificationChannel $channel, string $event): ?NotificationDelivery
    {
        try {
            $delivery = NotificationDelivery::create([
                'public_id' => 'nd_'.Str::lower((string) Str::ulid()),
                'tenant_id' => $incident->tenant_id,
                'incident_id' => $incident->id,
                'channel_id' => $channel->id,
                'event' => $event,
                'status' => 'queued',
            ]);
        } catch (QueryException $e) {
            // Unique constraint violation: (incident_id, channel_id, event) already dispatched.
            return null;
        }

        $payload = [
            'event' => $event,
            'incident_id' => $incident->public_id,
            'monitor_id' => $incident->monitor?->public_id,
            'monitor_name' => $incident->monitor?->name ?? 'System Monitor',
            'summary' => $this->redactor->redactString((string) $incident->summary),
            'severity' => $incident->severity,
            'started_at' => $incident->started_at?->toIso8601String(),
            'confirmed_at' => $incident->confirmed_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'duration_seconds' => $incident->duration_seconds,
        ];

        $transport = $this->transports->for((string) $channel->type);

        if ($transport === null) {
            $delivery->update([
                'status' => 'failed',
                'error_excerpt' => "Unsupported notification channel type: {$channel->type}",
            ]);

            return $delivery;
        }

        $result = $transport->send($channel, $payload);

        $delivery->update([
            'status' => $result['status'],
            'response_code' => $result['response_code'],
            'error_excerpt' => $result['error_excerpt'],
            'sent_at' => $result['status'] === 'sent' ? now() : null,
        ]);

        return $delivery;
    }
}
