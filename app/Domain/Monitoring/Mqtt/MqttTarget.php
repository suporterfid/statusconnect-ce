<?php

namespace App\Domain\Monitoring\Mqtt;

use InvalidArgumentException;

/**
 * An MQTT monitor target (#200): `mqtt://host[:port]` (plain TCP, default
 * 1883) or `mqtts://host[:port]` (TLS, default 8883).
 */
final readonly class MqttTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public bool $tls,
    ) {
    }

    public static function parse(string $target): self
    {
        if (! preg_match('#^(mqtts?)://(\[[0-9a-fA-F:]+\]|[A-Za-z0-9.-]+)(?::(\d{1,5}))?/?$#', trim($target), $m)) {
            throw new InvalidArgumentException('An MQTT target is mqtt://host:port or mqtts://host:port.');
        }
        $tls = strtolower($m[1]) === 'mqtts';
        $port = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : ($tls ? 8883 : 1883);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The MQTT port must be between 1 and 65535.');
        }

        return new self(strtolower(trim($m[2], '[]')), $port, $tls);
    }

    /** host:port, as the TCP target validator expects it. */
    public function hostPort(): string
    {
        return (str_contains($this->host, ':') ? '['.$this->host.']' : $this->host).':'.$this->port;
    }
}
