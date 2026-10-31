<?php

namespace App\Services\Fetching;

class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = @gethostbynamel($host) ?: [];

        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach ($aaaa ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
