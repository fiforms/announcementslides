<?php

namespace App\Services\Widgets;

/**
 * DNS lookup for SafeHttpFetcher, bound in the container so tests can
 * substitute fixed answers (rebinding, private addresses) without network.
 */
class HostResolver
{
    /** @return string[] every A/AAAA address for $host (or $host itself if it's an IP literal) */
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                if (isset($record[$field])) {
                    $ips[] = $record[$field];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
