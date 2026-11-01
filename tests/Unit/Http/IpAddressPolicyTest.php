<?php

namespace Tests\Unit\Http;

use App\Services\Http\IpAddressPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpAddressPolicyTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function blocked(): array
    {
        return array_map(fn ($ip) => [$ip], [
            'loopback v4' => '127.0.0.1', 'loopback range' => '127.255.0.9', 'this network' => '0.0.0.0',
            'private 10' => '10.1.2.3', 'private 172' => '172.16.0.1', 'private 172 top' => '172.31.255.255',
            'private 192' => '192.168.1.1', 'cgnat' => '100.64.0.1', 'link-local / metadata' => '169.254.169.254',
            'multicast' => '224.0.0.1', 'reserved' => '240.0.0.1', 'broadcast' => '255.255.255.255',
            'test-net-1' => '192.0.2.10', 'benchmark' => '198.18.0.1',
            'loopback v6' => '::1', 'unspecified v6' => '::', 'mapped v4 loopback' => '::ffff:127.0.0.1',
            'mapped v4 public still blocked' => '::ffff:8.8.8.8', 'unique local' => 'fd00::1',
            'aws v6 metadata' => 'fd00:ec2::254', 'link-local v6' => 'fe80::1', 'multicast v6' => 'ff02::1',
            'nat64' => '64:ff9b::7f00:1', '6to4' => '2002:7f00:1::1', 'teredo' => '2001:0:4136:e378::1',
            'documentation v6' => '2001:db8::1', 'garbage' => 'not-an-ip',
        ]);
    }

    #[DataProvider('blocked')]
    public function test_blocks_non_public(string $ip): void
    {
        $this->assertFalse((new IpAddressPolicy)->isPublic($ip));
    }

    /** @return array<string, array{0: string}> */
    public static function allowed(): array
    {
        return array_map(fn ($ip) => [$ip], [
            'public v4' => '93.184.216.34', 'google dns' => '8.8.8.8', 'edge of 172' => '172.32.0.1',
            'public v6' => '2606:4700:4700::1111',
        ]);
    }

    #[DataProvider('allowed')]
    public function test_allows_public(string $ip): void
    {
        $this->assertTrue((new IpAddressPolicy)->isPublic($ip));
    }
}
