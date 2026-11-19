<?php

namespace App\Domain\Notifications\Transports;

use App\Infrastructure\Persistence\Eloquent\NotificationChannel;

interface NotificationTransportInterface
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, response_code: ?int, error_excerpt: ?string}
     */
    public function send(NotificationChannel $channel, array $payload): array;
}
