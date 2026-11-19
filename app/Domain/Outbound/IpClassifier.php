<?php

// Ported / mirrored from TaskConnect: app/Domain/Execution/Outbound/IpClassifier.php

namespace App\Domain\Outbound;

final class IpClassifier
{
    /**
     * @param  list<string>  $metadataIps
     */
    public function __construct(
        private readonly array $metadataIps = [],
    ) {
    }

    public function isBlocked(string $ip): bool
    {
        if ($this->isMetadataIp($ip)) {
            return true;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isBlockedIpv4($ip);
        }

        return $this->isBlockedIpv6(strtolower($ip));
    }

    /**
     * @param  list<string>  $ips
     */
    public function containsBlocked(array $ips): bool
    {
        foreach ($ips as $ip) {
            if ($this->isBlocked($ip)) {
                return true;
            }
        }

        return false;
    }

    private function isMetadataIp(string $ip): bool
    {
        $normalized = strtolower($ip);

        foreach ($this->metadataIps as $metadataIp) {
            if (strcasecmp($normalized, strtolower($metadataIp)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Special-purpose IPv4 ranges a check must never reach (IANA IPv4
     * Special-Purpose Address Registry, plus multicast and reserved space).
     */
    private const BLOCKED_IPV4 = [
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // RFC 1918
        '100.64.0.0/10',      // RFC 6598 shared address space (CGNAT, Tailscale)
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local
        '172.16.0.0/12',      // RFC 1918
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation (TEST-NET-1)
        '192.168.0.0/16',     // RFC 1918
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation (TEST-NET-2)
        '203.0.113.0/24',     // documentation (TEST-NET-3)
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, including broadcast
    ];

    /**
     * IPv6 ranges refused outright.
     */
    private const BLOCKED_IPV6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '64:ff9b:1::/48',     // local-use NAT64
        '100::/64',           // discard-only
        '2001:db8::/32',      // documentation
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'fec0::/10',          // deprecated site-local
        'ff00::/8',           // multicast
    ];

    /**
     * IPv6 ranges that carry an IPv4 address: the embedded address is
     * classified as IPv4, so ::ffff:127.0.0.1 is refused like 127.0.0.1.
     * Value: byte offset of the embedded address.
     */
    private const EMBEDDED_IPV4 = [
        '::ffff:0:0/96' => 12,   // IPv4-mapped
        '::/96' => 12,           // IPv4-compatible (deprecated)
        '64:ff9b::/96' => 12,    // NAT64 well-known prefix
        '2002::/16' => 2,        // 6to4
    ];

    private function isBlockedIpv4(string $ip): bool
    {
        $packed = inet_pton($ip);

        return $packed === false || strlen($packed) !== 4 || $this->matchesAny($packed, self::BLOCKED_IPV4);
    }

    private function isBlockedIpv6(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        if ($this->matchesAny($packed, self::BLOCKED_IPV6)) {
            return true;
        }

        foreach (self::EMBEDDED_IPV4 as $cidr => $offset) {
            if ($this->matches($packed, $cidr)) {
                return $this->matchesAny(substr($packed, $offset, 4), self::BLOCKED_IPV4);
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $cidrs
     */
    private function matchesAny(string $packed, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if ($this->matches($packed, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = (string) inet_pton($network);
        if (strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        if (strncmp($packed, $networkPacked, $fullBytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }
}
