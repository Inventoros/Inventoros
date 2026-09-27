<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An approval action that cannot go ahead. `reason` is a stable code the
 * REST API, MCP tools and GraphQL surface as-is; `status` is the HTTP status
 * it maps to.
 */
class ApprovalException extends RuntimeException
{
    public const FORBIDDEN = 'forbidden';

    public const SELF_APPROVAL = 'self_approval';

    public const INVALID_STATE = 'invalid_state';

    public const NOT_REQUIRED = 'approval_not_required';

    public const NOT_FOUND = 'not_found';

    public const INSUFFICIENT_STOCK = 'insufficient_stock';

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return match ($this->reason) {
            self::FORBIDDEN, self::SELF_APPROVAL => 403,
            self::NOT_FOUND => 404,
            default => 422,
        };
    }

    public static function forbidden(string $message = 'You do not have permission to approve this request.'): self
    {
        return new self($message, self::FORBIDDEN);
    }

    public static function selfApproval(): self
    {
        return new self('You cannot approve your own request.', self::SELF_APPROVAL);
    }

    public static function invalidState(string $message): self
    {
        return new self($message, self::INVALID_STATE);
    }

    public static function notRequired(string $message): self
    {
        return new self($message, self::NOT_REQUIRED);
    }

    public static function notFound(string $message = 'Request not found.'): self
    {
        return new self($message, self::NOT_FOUND);
    }

    public static function insufficientStock(string $message): self
    {
        return new self($message, self::INSUFFICIENT_STOCK);
    }
}
