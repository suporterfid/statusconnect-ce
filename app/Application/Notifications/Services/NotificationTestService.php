<?php

namespace App\Application\Notifications\Services;

use App\Application\Notifications\NotificationTransports;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Infrastructure\Persistence\Eloquent\NotificationDelivery;
use Illuminate\Support\Str;

final class NotificationTestService
{
    public function __construct(
        private readonly NotificationTransports $transports,
    ) {
    }

    public function sendTestNotification(NotificationChannel $channel): NotificationDelivery
    {
        $delivery = NotificationDelivery::create([
            'public_id' => 'nd_'.Str::lower((string) Str::ulid()),
            'tenant_id' => $channel->tenant_id,
            'incident_id' => null,
            'channel_id' => $channel->id,
            'event' => 'test',
            'status' => 'queued',
        ]);

        $payload = [
            'event' => 'test',
            'incident_id' => 'inc_test_notification',
            'monitor_id' => 'mon_test_notification',
            'monitor_name' => 'StatusConnect Test Channel',
            'summary' => 'This is a test notification payload sent from StatusConnect to verify channel configuration.',
            'severity' => 'info',
            'started_at' => now()->toIso8601String(),
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
