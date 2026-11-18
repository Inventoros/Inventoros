<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

/**
 * Makes a provider's error message safe to store and show to administrators.
 *
 * Provider messages are written by core and by plugins and often quote the
 * request: a self-hosted provider's internal URL, or an API key a plugin put
 * in the query string. Before a message is logged, cached, stored as
 * last_sync_error or shown on Settings > Exchange rates, URL user info and
 * query strings are removed and anything that looks like a key or token is
 * replaced with [redacted]. People who only create purchase orders never see
 * the message at all, only a generic one.
 */
final class ProviderErrorMessage
{
    public const REDACTED = '[redacted]';

    private const MAX_LENGTH = 2000;

    /** Names whose value is a credential, in "name=value", "name: value" or JSON. */
    private const SECRET_NAMES = 'api[_-]?key|apikey|app[_-]?id|access[_-]?key|access[_-]?token|auth[_-]?token|refresh[_-]?token|client[_-]?secret|secret|token|password|passwd|pwd|signature|sig|key|authorization|auth|credentials?';

    public static function redact(?string $message): string
    {
        $message = trim((string) $message);

        if ($message === '') {
            return '';
        }

        // URLs: drop user info, query string and fragment, keep scheme, host and path.
        $message = (string) preg_replace_callback(
            '~\b([a-z][a-z0-9+.-]*://)([^\s/?#"\'<>]*@)?([^\s/?#"\'<>]*)([^\s?#"\'<>]*)(\?[^\s#"\'<>]*)?(#[^\s"\'<>]*)?~i',
            static fn (array $m): string => $m[1].(($m[2] ?? '') !== '' ? self::REDACTED.'@' : '').$m[3].($m[4] ?? '').(($m[5] ?? '') !== '' ? '?'.self::REDACTED : ''),
            $message,
        );

        // Bearer tokens: "Bearer abc".
        $message = (string) preg_replace('#\b(Bearer)\s+[A-Za-z0-9._~+/=-]+#i', '$1 '.self::REDACTED, $message);

        // name=value, name: value, "name":"value" (query fragments, headers, JSON).
        $message = (string) preg_replace(
            '~(["\']?\b(?:'.self::SECRET_NAMES.')\b["\']?\s*[:=]\s*)(?!\[redacted\])(["\']?)[^\s"\'&,;}]+\2~i',
            '$1$2'.self::REDACTED.'$2',
            $message,
        );

        // Long opaque strings with letters and digits (keys, tokens, hashes).
        $message = (string) preg_replace_callback(
            '~(?<![A-Za-z0-9])[A-Za-z0-9_\-]{24,}(?![A-Za-z0-9])~',
            static fn (array $m): string => preg_match('~[0-9]~', $m[0]) === 1 && preg_match('~[A-Za-z]~', $m[0]) === 1 ? self::REDACTED : $m[0],
            $message,
        );

        return mb_strlen($message) > self::MAX_LENGTH ? mb_substr($message, 0, self::MAX_LENGTH).'...' : $message;
    }
}
