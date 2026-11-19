<?php

namespace Tests\Feature;

use App\Domain\Outbound\ValidatedEndpoint;
use App\Infrastructure\Mqtt\MqttConnectProbe;
use Tests\TestCase;

/**
 * The MQTT CONNECT probe against the fake broker in the target service
 * (docker/target/fake-mqtt.js, #200).
 */
class MqttConnectProbeTest extends TestCase
{
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        $ip = gethostbyname('target');
        $socket = $ip === 'target' ? false : @stream_socket_client("tcp://{$ip}:1883", $errno, $errstr, 2);
        if ($socket === false) {
            $this->markTestSkipped('Fake MQTT broker in the target service is not reachable.');
        }
        fclose($socket);
        $this->ip = $ip;
    }

    public function test_accepted_connect_is_up(): void
    {
        $outcome = $this->check(1883, tls: false, username: 'monitor', password: 'secret-pass');

        $this->assertNull($outcome->transportError);
        $this->assertSame(200, $outcome->statusCode);
    }

    public function test_refused_connack_is_down_with_its_reason(): void
    {
        $this->assertSame('MQTT CONNACK refused: 4 (bad user name or password)', $this->check(1883, tls: false, username: 'monitor', password: 'wrong')->transportError);
        $this->assertSame('MQTT CONNACK refused: 3 (server unavailable)', $this->check(1883, tls: false, username: 'down')->transportError);
        $this->assertSame('MQTT CONNACK refused: 5 (not authorized)', $this->check(1883, tls: false)->transportError);
    }

    public function test_tls_with_and_without_verification(): void
    {
        $this->assertNull($this->check(8883, tls: true, verify: false, username: 'monitor', password: 'secret-pass')->transportError);

        // The test certificate is self-signed: verification must refuse it.
        $verified = $this->check(8883, tls: true, verify: true, username: 'monitor', password: 'secret-pass');
        $this->assertStringStartsWith('MQTT TLS failed', (string) $verified->transportError);
    }

    public function test_a_proxy_that_accepts_but_never_answers_times_out(): void
    {
        $outcome = $this->check(1884, tls: false, username: 'monitor', password: 'secret-pass', timeoutMs: 1000);

        $this->assertSame('MQTT broker sent no CONNACK in time', $outcome->transportError);
        $this->assertGreaterThanOrEqual(900, $outcome->latencyMs);
        $this->assertLessThan(3000, $outcome->latencyMs);
    }

    public function test_a_closed_port_fails_to_connect(): void
    {
        $this->assertStringStartsWith('MQTT connect failed', (string) $this->check(1885, tls: false)->transportError);
    }

    private function check(int $port, bool $tls, bool $verify = true, ?string $username = null, ?string $password = null, int $timeoutMs = 3000): \App\Domain\Monitoring\CheckOutcome
    {
        $endpoint = new ValidatedEndpoint("tcp://target:$port", 'tcp', 'target', $port, $this->ip, [$this->ip], false);

        return (new MqttConnectProbe)->check($endpoint, $tls, $verify, 'sc-test', $username, $password, $timeoutMs);
    }
}
