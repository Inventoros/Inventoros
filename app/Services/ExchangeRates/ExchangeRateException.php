<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use RuntimeException;
use Throwable;

/**
 * A provider could not deliver rates. $reason says why, so core can decide
 * whether to retry (unavailable, rate_limited) or not (unsupported,
 * invalid_response, misconfigured).
 *
 * @api
 */
final class ExchangeRateException extends RuntimeException
{
    public const UNAVAILABLE = 'unavailable';

    public const RATE_LIMITED = 'rate_limited';

    public const UNSUPPORTED = 'unsupported';

    public const INVALID_RESPONSE = 'invalid_response';

    public const MISCONFIGURED = 'misconfigured';

    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(string $message, ?Throwable $previous = null): self
    {
        return new self($message, self::UNAVAILABLE, null, $previous);
    }

    public static function rateLimited(string $message, ?int $retryAfterSeconds = null): self
    {
        return new self($message, self::RATE_LIMITED, $retryAfterSeconds);
    }

    public static function unsupported(string $message): self
    {
        return new self($message, self::UNSUPPORTED);
    }

    public static function invalidResponse(string $message): self
    {
        return new self($message, self::INVALID_RESPONSE);
    }

    public static function misconfigured(string $message): self
    {
        return new self($message, self::MISCONFIGURED);
    }

    /**
     * Whether asking again later may succeed.
     */
    public function isTransient(): bool
    {
        return in_array($this->reason, [self::UNAVAILABLE, self::RATE_LIMITED], true);
    }
}
