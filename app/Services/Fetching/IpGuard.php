<?php

namespace App\Services\Fetching;

/**
 * Decides whether an IP address is a public, routable destination.
 */
class IpGuard
{
    /** Ranges not fully covered by PHP's NO_PRIV_RANGE / NO_RES_RANGE flags. */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',
        '100.64.0.0/10',    // carrier-grade NAT
        '127.0.0.0/8',
        '169.254.0.0/16',   // link-local, cloud metadata
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',
        '255.255.255.255/32',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',    // IPv4-mapped (checked separately below)
        '64:ff9b::/96',     // NAT64
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',         // unique local
        'fe80::/10',        // link-local
        'ff00::/8',         // multicast
    ];

    public static function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Unwrap IPv4-mapped IPv6 (::ffff:127.0.0.1) and judge the embedded IPv4 address.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            return self::isPublic($m[1]);
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return ($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask);
    }
}
