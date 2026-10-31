<?php

namespace Tests\Unit;

use App\Services\Fetching\IpGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpGuardTest extends TestCase
{
    public static function blocked(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '127.0.0.1', '127.1.2.3', '10.0.0.5', '172.16.0.1', '172.31.255.255', '192.168.1.1',
            '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255', '198.18.0.1',
            '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::a00:1',
            'not-an-ip',
        ]);
    }

    #[DataProvider('blocked')]
    public function test_private_and_reserved_addresses_are_blocked(string $ip): void
    {
        $this->assertFalse(IpGuard::isPublic($ip), "{$ip} should be blocked");
    }

    public function test_public_addresses_are_allowed(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '172.32.0.1', '100.128.0.1'] as $ip) {
            $this->assertTrue(IpGuard::isPublic($ip), "{$ip} should be allowed");
        }
    }

    public function test_cidr_matching(): void
    {
        $this->assertTrue(IpGuard::inCidr('100.127.255.255', '100.64.0.0/10'));
        $this->assertFalse(IpGuard::inCidr('100.128.0.0', '100.64.0.0/10'));
        $this->assertFalse(IpGuard::inCidr('10.0.0.1', 'fc00::/7'));
    }
}
