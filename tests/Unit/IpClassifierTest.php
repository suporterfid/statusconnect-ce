<?php

namespace Tests\Unit;

use App\Domain\Outbound\IpClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Special-purpose address ranges refused by the outbound policy (#107).
 */
class IpClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function blocked(): array
    {
        return self::cases([
            // Unchanged ranges.
            '0.0.0.0', '127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1',
            '169.254.169.254', '224.0.0.1', '255.255.255.255',
            // Newly refused.
            '0.1.2.3',
            '100.64.0.0', '100.64.0.1', '100.100.100.100', '100.127.255.255',
            '192.0.0.1', '192.0.2.10', '198.18.0.1', '198.19.255.255', '198.51.100.7', '203.0.113.9',
            // IPv6.
            '::', '::1', 'fe80::1', 'febf::1', 'fec0::1', 'fc00::1', 'fd12:3456::1', 'ff02::1',
            '2001:db8::1', '100::1', '64:ff9b:1::1',
            // IPv6 forms that embed a refused IPv4 address.
            '::ffff:127.0.0.1', '::ffff:7f00:1', '::ffff:10.0.0.1', '::ffff:100.64.0.1',
            '::127.0.0.1', '64:ff9b::a9fe:a9fe', '2002:7f00:1::1', '2002:a00:1::',
            // Not an address.
            'not-an-ip', '256.1.1.1',
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowed(): array
    {
        return self::cases([
            '1.1.1.1', '8.8.8.8', '93.184.215.14',
            // Just outside the new ranges.
            '100.63.255.255', '100.128.0.1', '192.0.1.1', '198.17.255.255', '198.20.0.1',
            '172.32.0.1', '203.0.114.1',
            // Public IPv6, and IPv6 forms that embed a public IPv4 address.
            '2606:4700:4700::1111', '2001:4860:4860::8888', '::ffff:8.8.8.8', '64:ff9b::808:808', '2002:808:808::1',
        ]);
    }

    /**
     * @param  list<string>  $ips
     * @return array<string, array{string}>
     */
    private static function cases(array $ips): array
    {
        return array_combine($ips, array_map(static fn (string $ip): array => [$ip], $ips));
    }

    #[DataProvider('blocked')]
    public function test_refuses_special_purpose_addresses(string $ip): void
    {
        $this->assertTrue((new IpClassifier)->isBlocked($ip), "{$ip} should be refused");
    }

    #[DataProvider('allowed')]
    public function test_allows_public_addresses(string $ip): void
    {
        $this->assertFalse((new IpClassifier)->isBlocked($ip), "{$ip} should be allowed");
    }

    public function test_metadata_ips_are_refused(): void
    {
        $classifier = new IpClassifier(['203.0.114.254', 'fd00:ec2::254']);

        $this->assertTrue($classifier->isBlocked('203.0.114.254'));
        $this->assertTrue($classifier->containsBlocked(['8.8.8.8', '203.0.114.254']));
        $this->assertFalse($classifier->containsBlocked(['8.8.8.8', '1.1.1.1']));
    }
}
