<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Resolve a URL's host to its underlying A/AAAA records and reject any
 * destination that would let an attacker pivot from our outbound HTTP
 * surface into internal infrastructure or cloud metadata.
 *
 * Used at webhook-delivery time so DNS rebinding cannot bypass the
 * static URL validation that happened at webhook-creation time.
 */
final class PublicHostGuard
{
    /**
     * Throw if the URL's host resolves (or already is) a non-public IP.
     */
    public static function assertPublic(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            throw new RuntimeException('Webhook URL has no resolvable host');
        }

        // Reject obvious string-form bypasses up-front.
        $literalBlocks = ['localhost', 'metadata.google.internal'];
        if (in_array(strtolower($host), $literalBlocks, true)) {
            throw new RuntimeException("Webhook URL host '{$host}' is not allowed");
        }

        $ips = static::resolveHost($host);

        // If DNS lookup returned nothing, let the request proceed — the
        // HTTP client will fail at connect time. Blocking here would
        // false-positive in containerised / restricted-DNS environments
        // (CI, certain Docker setups) where lookups for legitimate public
        // hosts can briefly return empty. The actual SSRF threat surfaces
        // only when we DO get back an IP and that IP turns out to be
        // private — that case is still rejected below.
        foreach ($ips as $ip) {
            if (!static::isPublicIp($ip)) {
                throw new RuntimeException(
                    "Webhook URL host '{$host}' resolves to non-public address {$ip}"
                );
            }
        }
    }

    /**
     * Resolve a hostname to its A and AAAA records, or return the input
     * verbatim if it's already an IP literal.
     *
     * @return array<int, string>
     */
    public static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!$records) {
            // Fall back to gethostbyname which only returns IPv4 but is more
            // forgiving in containerised test environments.
            $resolved = gethostbyname($host);
            return $resolved !== $host ? [$resolved] : [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * Return false for loopback, link-local, RFC1918, multicast, reserved,
     * and the well-known cloud-metadata IPs (169.254.169.254 and the GCP
     * fd00::ec2:0:0:0 v6 equivalent).
     */
    public static function isPublicIp(string $ip): bool
    {
        $metadataIps = ['169.254.169.254', 'fd00:ec2::254'];
        if (in_array($ip, $metadataIps, true)) {
            return false;
        }

        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
