<?php

namespace App\Infrastructure\Mqtt;

use App\Domain\Monitoring\CheckOutcome;
use App\Domain\Monitoring\Mqtt\MqttPackets;
use App\Domain\Outbound\ValidatedEndpoint;

/**
 * Runs one MQTT CONNECT check (#200): TCP to the pinned IP, optionally TLS
 * with the target's host name for SNI and verification, CONNECT, then wait for
 * CONNACK and DISCONNECT. Nothing stays open after the call. The check proves
 * the whole path to the broker, which a TCP connect to a proxy in front of it
 * does not.
 */
class MqttConnectProbe
{
    /**
     * @param  int  $timeoutMs  For the whole exchange: connect, TLS, CONNECT and CONNACK.
     */
    public function check(
        ValidatedEndpoint $endpoint,
        bool $tls,
        bool $verifyTls,
        string $clientId,
        ?string $username,
        ?string $password,
        int $timeoutMs,
    ): CheckOutcome {
        $started = hrtime(true);
        $deadline = $started + max(1, $timeoutMs) * 1_000_000;
        $elapsed = static fn (): int => (int) round((hrtime(true) - $started) / 1_000_000);
        $remaining = static fn (): float => max(0.001, ($deadline - hrtime(true)) / 1_000_000_000);

        $context = stream_context_create(['ssl' => [
            'peer_name' => $endpoint->host,
            'SNI_enabled' => true,
            'verify_peer' => $verifyTls,
            'verify_peer_name' => $verifyTls,
            'allow_self_signed' => ! $verifyTls,
        ]]);
        $address = sprintf('tcp://%s:%d', str_contains($endpoint->pinnedIp, ':') ? '['.$endpoint->pinnedIp.']' : $endpoint->pinnedIp, $endpoint->port);

        $socket = @stream_socket_client($address, $errno, $errstr, $remaining(), STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return CheckOutcome::transportError('MQTT connect failed: '.($errstr !== '' ? $errstr : 'error '.$errno), $elapsed());
        }

        try {
            stream_set_timeout($socket, (int) floor($remaining()), (int) (fmod($remaining(), 1) * 1_000_000));

            if ($tls) {
                $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                if ($ok !== true) {
                    $error = error_get_last()['message'] ?? 'handshake failed';

                    return CheckOutcome::transportError('MQTT TLS failed: '.$this->shorten($error), $elapsed());
                }
            }

            if (@fwrite($socket, MqttPackets::connect($clientId, $username, $password)) === false) {
                return CheckOutcome::transportError('MQTT CONNECT could not be sent', $elapsed());
            }

            $bytes = '';
            while (strlen($bytes) < 4 && hrtime(true) < $deadline) {
                stream_set_timeout($socket, (int) floor($remaining()), (int) (fmod($remaining(), 1) * 1_000_000));
                $chunk = @fread($socket, 4 - strlen($bytes));
                if ($chunk === false || ($chunk === '' && (feof($socket) || stream_get_meta_data($socket)['timed_out']))) {
                    break;
                }
                $bytes .= $chunk;
            }
            $latency = $elapsed();

            $code = MqttPackets::connackCode($bytes);
            if ($code === null) {
                return CheckOutcome::transportError(
                    $bytes === '' ? 'MQTT broker sent no CONNACK in time' : 'MQTT broker answered with something other than CONNACK',
                    $latency,
                );
            }

            @fwrite($socket, MqttPackets::DISCONNECT);
            if ($code !== 0) {
                return CheckOutcome::transportError(sprintf('MQTT CONNACK refused: %d (%s)', $code, MqttPackets::RETURN_CODES[$code] ?? 'unknown'), $latency);
            }

            return new CheckOutcome(statusCode: 200, latencyMs: $latency);
        } finally {
            @fclose($socket);
        }
    }

    private function shorten(string $message): string
    {
        return mb_substr(preg_replace('/^stream_socket_enable_crypto\(\): /', '', $message) ?? $message, 0, 200);
    }
}
