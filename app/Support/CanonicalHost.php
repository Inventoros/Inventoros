<?php

declare(strict_types=1);

namespace App\Support;

use GuzzleHttp\Psr7\Uri;
use Throwable;

/**
 * Extract a URL's host only when it is written in canonical form.
 *
 * Host allowlists compare the host parse_url() sees, but the HTTP client
 * parses the URL again to decide where to connect. Any form the two can read
 * differently is a way around the allowlist: userinfo (`allowed@evil`), a
 * backslash (`evil\@allowed`), percent-escapes, non-ASCII lookalikes, empty
 * labels or a trailing dot. Those are refused rather than normalised, so the
 * only thing left to compare is a plain lowercase LDH hostname that Guzzle
 * agrees on. Internationalised names must be configured in punycode.
 */
final class CanonicalHost
{
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * The lowercase host of an absolute URL, or null when the URL has no host
     * or its host is not canonical.
     */
    public static function of(string $url): ?string
    {
        // Printable ASCII only, and no backslash: parsers disagree on both.
        if ($url === '' || preg_match('/[^\x21-\x7E]|\\\\/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || strlen($host) > 253
            || preg_match('/^(?:'.self::LABEL.'\.)*'.self::LABEL.'$/', $host) !== 1) {
            return null;
        }

        // The HTTP client must see exactly the same host.
        try {
            $clientHost = strtolower((new Uri($url))->getHost());
        } catch (Throwable) {
            return null;
        }

        return $clientHost === $host ? $host : null;
    }
}
