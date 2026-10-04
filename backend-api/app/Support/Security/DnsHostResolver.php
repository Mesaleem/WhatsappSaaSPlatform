<?php

namespace App\Support\Security;

/** Production resolver: the system resolver (hosts file included) plus an explicit A/AAAA lookup, merged. */
class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        foreach (@gethostbynamel($host) ?: [] as $ip) {
            $ips[] = $ip;
        }

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
