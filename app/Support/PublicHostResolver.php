<?php

declare(strict_types=1);

namespace App\Support;

/** DNS is injectable so delivery tests never depend on external resolvers. */
class PublicHostResolver
{
    /** @return array<int, string> */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            $resolved = gethostbyname($host);
            $ips = $resolved !== $host ? [$resolved] : [];
        }

        return array_values(array_unique($ips));
    }
}
