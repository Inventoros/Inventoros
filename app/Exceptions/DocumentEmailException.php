<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A document (purchase order, invoice) could not be emailed for a reason the
 * user can fix: no recipient on file, or a status that does not allow it.
 * The message is safe to show to the user as-is; $reason is a stable
 * machine-readable code for API and MCP callers.
 */
class DocumentEmailException extends BusinessRuleException
{
    public const MISSING_RECIPIENT = 'missing_recipient';

    public const NOT_SENDABLE = 'cannot_send';

    public const APPROVAL_REQUIRED = 'approval_required';

    public const RATE_LIMITED = 'rate_limited';

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * The HTTP status for API callers: 429 when throttled, otherwise 422.
     */
    public function status(): int
    {
        return $this->reason === self::RATE_LIMITED ? 429 : 422;
    }

    public static function rateLimited(string $message): self
    {
        return new self($message, self::RATE_LIMITED);
    }

    public static function missingRecipient(string $message): self
    {
        return new self($message, self::MISSING_RECIPIENT);
    }

    public static function notSendable(string $message): self
    {
        return new self($message, self::NOT_SENDABLE);
    }

    public static function approvalRequired(string $message): self
    {
        return new self($message, self::APPROVAL_REQUIRED);
    }
}
