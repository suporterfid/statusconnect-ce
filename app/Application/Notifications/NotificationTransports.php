<?php

namespace App\Application\Notifications;

use App\Domain\Notifications\Transports\NotificationTransportInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Notification channel types and the transport that sends each (#250).
 *
 * The core registers email, webhook, taskconnect and telegram; an edition
 * may register more. A channel of an unregistered type fails its deliveries
 * with "Unsupported notification channel type".
 */
final class NotificationTransports
{
    /** @var array<string, class-string<NotificationTransportInterface>> */
    private array $types = [];

    public function __construct(private readonly Container $container)
    {
    }

    /** @param  class-string<NotificationTransportInterface>  $transport */
    public function register(string $type, string $transport): void
    {
        $this->types[$type] = $transport;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function for(string $type): ?NotificationTransportInterface
    {
        return isset($this->types[$type]) ? $this->container->make($this->types[$type]) : null;
    }
}
