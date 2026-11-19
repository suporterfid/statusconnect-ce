<?php

namespace App\Domain\Monitoring\Mqtt;

/**
 * The few MQTT 3.1.1 packets a CONNECT check needs (#200): CONNECT, CONNACK
 * and DISCONNECT. See the OASIS MQTT 3.1.1 standard, sections 3.1, 3.2 and 3.14.
 */
final class MqttPackets
{
    public const DISCONNECT = "\xE0\x00";

    /** CONNACK return codes (3.2.2.3). */
    public const RETURN_CODES = [
        0 => 'Connection accepted',
        1 => 'unacceptable protocol version',
        2 => 'client identifier rejected',
        3 => 'server unavailable',
        4 => 'bad user name or password',
        5 => 'not authorized',
    ];

    public static function connect(string $clientId, ?string $username, ?string $password, int $keepAliveSeconds = 30): string
    {
        $flags = 0x02; // clean session
        $payload = self::string($clientId);
        if ($username !== null && $username !== '') {
            $flags |= 0x80;
            $payload .= self::string($username);
            if ($password !== null && $password !== '') {
                $flags |= 0x40;
                $payload .= self::string($password);
            }
        }

        $variableHeader = self::string('MQTT')."\x04".chr($flags).pack('n', $keepAliveSeconds);

        return "\x10".self::remainingLength(strlen($variableHeader) + strlen($payload)).$variableHeader.$payload;
    }

    /**
     * Reads a CONNACK. Returns the return code, or null when the bytes are not
     * a CONNACK.
     */
    public static function connackCode(string $bytes): ?int
    {
        if (strlen($bytes) < 4 || ord($bytes[0]) !== 0x20 || ord($bytes[1]) !== 0x02) {
            return null;
        }

        return ord($bytes[3]);
    }

    private static function string(string $value): string
    {
        return pack('n', strlen($value)).$value;
    }

    private static function remainingLength(int $length): string
    {
        $out = '';
        do {
            $byte = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        } while ($length > 0);

        return $out;
    }
}
