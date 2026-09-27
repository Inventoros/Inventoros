<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A carrier API call failed (bad credentials, invalid address, no rates,
 * network error). The message is a readable sentence built from the carrier's
 * error payload; the raw payload travels in $details for logging.
 */
final class CarrierException extends ShippingException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, public readonly array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
