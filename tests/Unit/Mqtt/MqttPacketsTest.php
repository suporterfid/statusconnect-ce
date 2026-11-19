<?php

namespace Tests\Unit\Mqtt;

use App\Domain\Monitoring\Mqtt\MqttPackets;
use App\Domain\Monitoring\Mqtt\MqttTarget;
use PHPUnit\Framework\TestCase;

/** MQTT 3.1.1 packets and targets for MQTT monitors (#200). */
class MqttPacketsTest extends TestCase
{
    public function test_connect_without_credentials(): void
    {
        // Fixed header 0x10, remaining length 14, "MQTT", level 4, clean session, keep alive 30, client id "sc".
        $this->assertSame(
            "\x10\x0e\x00\x04MQTT\x04\x02\x00\x1e\x00\x02sc",
            MqttPackets::connect('sc', null, null),
        );
    }

    public function test_connect_with_user_name_and_password(): void
    {
        $packet = MqttPackets::connect('sc', 'monitor', 'secret-pass');

        $this->assertSame(0x10, ord($packet[0]));
        $this->assertSame(strlen($packet) - 2, ord($packet[1]));
        $this->assertSame(0xC2, ord($packet[9]), 'user name, password and clean session flags');
        $this->assertStringEndsWith("\x00\x07monitor\x00\x0bsecret-pass", $packet);
    }

    public function test_long_payload_uses_a_multi_byte_remaining_length(): void
    {
        $packet = MqttPackets::connect('sc', 'u', str_repeat('p', 200));

        // 10 bytes of variable header + 4 (client id) + 3 (user) + 202 (password) = 219 = 0xDB 0x01.
        $this->assertSame("\x10\xdb\x01", substr($packet, 0, 3));
        $this->assertSame(3 + 219, strlen($packet));
    }

    public function test_connack(): void
    {
        $this->assertSame(0, MqttPackets::connackCode("\x20\x02\x00\x00"));
        $this->assertSame(5, MqttPackets::connackCode("\x20\x02\x00\x05"));
        $this->assertNull(MqttPackets::connackCode("\x20\x02\x00"));
        $this->assertNull(MqttPackets::connackCode('HTTP'));
    }

    public function test_targets(): void
    {
        $plain = MqttTarget::parse('mqtt://Broker.Example.com');
        $this->assertSame(['broker.example.com', 1883, false], [$plain->host, $plain->port, $plain->tls]);
        $tls = MqttTarget::parse('mqtts://mqtt.example.com:8884');
        $this->assertSame(['mqtt.example.com', 8884, true], [$tls->host, $tls->port, $tls->tls]);
        $this->assertSame(8883, MqttTarget::parse('mqtts://broker.example.com/')->port);
        $this->assertSame('[2001:db8::1]:1883', MqttTarget::parse('mqtt://[2001:db8::1]')->hostPort());

        foreach (['broker.example.com:1883', 'tcp://broker:1883', 'mqtts://broker:99999', 'mqtt://broker/topic', 'mqtt://'] as $bad) {
            try {
                MqttTarget::parse($bad);
                $this->fail("accepted $bad");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
