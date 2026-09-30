<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by a domain service when an action is not allowed in the record's
 * current state (e.g. receiving a return that has not been approved).
 *
 * Each surface translates it to its own error contract: web controllers flash
 * the message, the REST API returns a 422 with the machine-readable
 * `$errorCode`, and GraphQL surfaces it as a resolver error. Extends
 * BusinessRuleException, which request handlers catch to show the message.
 */
final class InvalidStateException extends BusinessRuleException
{
    public function __construct(string $message, public readonly string $errorCode = 'invalid_state')
    {
        parent::__construct($message);
    }
}
