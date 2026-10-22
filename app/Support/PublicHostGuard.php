<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Resolve a URL's host to its underlying A/AAAA records and reject any
 * destination that would let an attacker pivot from our outbound HTTP
 * surface into internal infrastructure or cloud metadata.
 *
 * Delivery must use connectionOptions(), which pins the checked answer to
 * cURL. A validation-only lookup cannot prevent a second DNS resolution.
 */
final class PublicHostGuard
{
    /**
     * Throw if the URL's host resolves (or already is) a non-public IP.
     */
    public static function assertPublic(string $url): void
    {
        self::validatedTarget($url, app()->environment('production'));
    }

    /**
     * Require a direct cURL connection to this exact, checked DNS answer.
     * The URL remains unchanged, preserving Host, TLS SNI and certificate checks.
     *
     * @return array<string, mixed>
     */
    public static function connectionOptions(string $url): array
    {
        if (! function_exists('curl_exec') || curl_version()['version_number'] < 0x073B00) {
            throw new RuntimeException('Secure webhook delivery requires PHP cURL with libcurl 7.59 or newer');
        }

        $target = self::validatedTarget($url, true);
        $curl = [
            CURLOPT_PROXY => '',
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
        ];
        if (! filter_var($target['host'], FILTER_VALIDATE_IP)) {
            $addresses = array_map(fn (string $ip) => str_contains($ip, ':') ? "[{$ip}]" : $ip, $target['ips']);
            $curl[CURLOPT_RESOLVE] = [$target['host'].':'.$target['port'].':'.implode(',', $addresses)];
        }

        return ['allow_redirects' => false, 'proxy' => '', 'curl' => $curl];
    }

    /** @return array{host: string, port: int, ips: array<int, string>} */
    private static function validatedTarget(string $url, bool $requireDns): array
    {
        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (! $host) {
            throw new RuntimeException('Webhook URL has no resolvable host');
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Webhook URL must use HTTP or HTTPS');
        }
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                || preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\.?$/i', $host)
            ) {
                throw new RuntimeException('Webhook URL host must be a hostname or canonical IP address');
            }
        }

        // Reject obvious string-form bypasses up-front.
        $literalBlocks = ['localhost', 'metadata.google.internal'];
        if (in_array(rtrim($host, '.'), $literalBlocks, true)) {
            throw new RuntimeException("Webhook URL host '{$host}' is not allowed");
        }

        $ips = self::resolveHost($host);

        // Validation-only callers retain their local-development behavior;
        // actual delivery fails closed in every environment.
        if ($ips === [] && $requireDns) {
            throw new RuntimeException(
                "Webhook URL host '{$host}' did not resolve to any address"
            );
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new RuntimeException(
                    "Webhook URL host '{$host}' resolves to non-public address {$ip}"
                );
            }
        }

        return ['host' => $host, 'port' => $parts['port'] ?? ($scheme === 'https' ? 443 : 80), 'ips' => $ips];
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

        return app(PublicHostResolver::class)->resolve($host);
    }

    /**
     * Return false for loopback, link-local, RFC1918, multicast, reserved,
     * and the well-known cloud-metadata IPs (169.254.169.254 and the GCP
     * fd00::ec2:0:0:0 v6 equivalent).
     */
    public static function isPublicIp(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        // PHP's global-range filter still accepts multicast, which must
        // never be used as a webhook destination.
        if ((strlen($binary) === 4 && ord($binary[0]) >= 224)
            || (strlen($binary) === 16 && ord($binary[0]) === 255)
        ) {
            return false;
        }

        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_GLOBAL_RANGE
        );
    }
}
