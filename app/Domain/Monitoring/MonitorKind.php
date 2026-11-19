<?php

namespace App\Domain\Monitoring;

enum MonitorKind: string
{
    case HTTP = 'http';
    case TCP = 'tcp';
    case HEARTBEAT = 'heartbeat';
    case AGENT = 'agent';
    // CONNECT/CONNACK against an MQTT broker (#200).
    case MQTT = 'mqtt';

    public static function tryFromMixed(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::HTTP;
        }

        return self::tryFrom($value) ?? self::HTTP;
    }
}
