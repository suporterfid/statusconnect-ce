<?php

namespace App\Domain\Notifications\Transports;

use App\Domain\Notifications\Services\MailRateLimiter;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Mail\IncidentNotificationMail;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class EmailNotificationTransport implements NotificationTransportInterface
{
    public function __construct(private readonly MailRateLimiter $rateLimiter)
    {
    }

    public function send(NotificationChannel $channel, array $payload): array
    {
        $config = $channel->config_json ?? [];
        $recipient = $config['email'] ?? $config['recipient'] ?? null;

        if (! is_string($recipient) || trim($recipient) === '') {
            return [
                'status' => 'failed',
                'response_code' => 400,
                'error_excerpt' => 'No valid recipient email address configured.',
            ];
        }

        $maxPerHour = (int) env('NOTIFY_MAIL_MAX_PER_HOUR', 100);
        if (! $this->rateLimiter->allowsEmail($channel->tenant_id, $maxPerHour)) {
            return [
                'status' => 'failed',
                'response_code' => 429,
                'error_excerpt' => 'Mail rate limit exceeded (coalesced digest mode).',
            ];
        }

        try {
            Mail::to(trim($recipient))->send(new IncidentNotificationMail($payload));

            return [
                'status' => 'sent',
                'response_code' => 200,
                'error_excerpt' => null,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'failed',
                'response_code' => 500,
                'error_excerpt' => mb_substr($e->getMessage(), 0, 500),
            ];
        }
    }
}
