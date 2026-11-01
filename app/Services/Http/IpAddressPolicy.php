<?php

namespace App\Services\Http;

/**
 * Decides whether an IP address is a public, globally routable unicast address that the server may
 * connect to. Everything else (loopback, private, link-local, CGNAT, multicast, reserved, documentation,
 * benchmarking, NAT64/6to4/Teredo-embedded addresses, cloud metadata) is refused.
 */
class IpAddressPolicy
{
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // "this" network
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local (incl. 169.254.169.254 cloud metadata)
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.31.196.0/24',    // AS112
        '192.52.193.0/24',    // AMT
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // private
        '192.175.48.0/24',    // AS112
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved + broadcast
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4-mapped (checked separately against v4 rules, then blocked anyway)
        '::/96',              // IPv4-compatible (deprecated)
        '64:ff9b::/96',       // NAT64
        '64:ff9b:1::/48',     // local-use NAT64
        '100::/64',           // discard-only
        '2001::/23',          // IETF protocol assignments (incl. Teredo 2001::/32)
        '2001:db8::/32',      // documentation
        '2002::/16',          // 6to4
        '3fff::/20',          // documentation
        '5f00::/16',          // SRv6 SIDs
        'fc00::/7',           // unique local (incl. fd00:ec2::254 metadata)
        'fe80::/10',          // link-local
        'fec0::/10',          // site-local (deprecated)
        'ff00::/8',           // multicast
    ];

    public function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        $blocked = strlen($packed) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6;
        foreach ($blocked as $cidr) {
            if ($this->inCidr($packed, $cidr)) {
                return false;
            }
        }

        // Only 2000::/3 is globally routable unicast for IPv6.
        if (strlen($packed) === 16 && (ord($packed[0]) & 0xE0) !== 0x20) {
            return false;
        }

        return true;
    }

    private function inCidr(string $packedIp, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $packedNet = inet_pton($net);
        if ($packedNet === false || strlen($packedNet) !== strlen($packedIp)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($packedIp, $packedNet, $bytes) !== 0) {
            return false;
        }
        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($packedIp[$bytes]) & $mask) === (ord($packedNet[$bytes]) & $mask);
    }
}
