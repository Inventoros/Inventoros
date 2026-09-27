<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Exceptions\DocumentEmailException;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Shared recipient handling for emailed documents (purchase orders, invoices):
 * pick and validate the primary recipient, tidy the CC list, and record the
 * send in the activity log against the acting user.
 */
final class DocumentRecipients
{
    public const MAX_CC = 5;

    public const MAX_MESSAGE_LENGTH = 2000;

    /**
     * The explicit override if given, otherwise the address on file.
     *
     * @throws DocumentEmailException
     */
    public static function primary(?string $override, ?string $onFile, string $missingMessage): string
    {
        $override = trim((string) $override);
        $recipient = $override !== '' ? $override : trim((string) $onFile);

        if ($recipient === '') {
            throw DocumentEmailException::missingRecipient($missingMessage);
        }

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw DocumentEmailException::missingRecipient("{$recipient} is not a valid email address.");
        }

        return $recipient;
    }

    /**
     * Validate and de-duplicate CC addresses, dropping the primary recipient.
     *
     * @param  array<int, mixed>  $cc
     * @return array<int, string>
     *
     * @throws DocumentEmailException
     */
    public static function cc(array $cc, string $primary): array
    {
        $clean = [];

        foreach ($cc as $address) {
            $address = trim((string) $address);

            if ($address === '' || strcasecmp($address, $primary) === 0) {
                continue;
            }

            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw DocumentEmailException::missingRecipient("{$address} is not a valid email address.");
            }

            $clean[strtolower($address)] = $address;
        }

        return array_slice(array_values($clean), 0, self::MAX_CC);
    }

    public static function message(?string $message): ?string
    {
        $message = trim((string) $message);

        return $message === '' ? null : mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * Split a comma, semicolon or whitespace separated string (from the web
     * form) into addresses; arrays pass through trimmed.
     *
     * @return array<int, string>
     */
    public static function split(string|array|null $value): array
    {
        $parts = is_array($value)
            ? $value
            : (preg_split('/[,;\s]+/', (string) $value) ?: []);

        return array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), $parts),
            fn (string $v) => $v !== ''
        ));
    }

    /**
     * Record who sent the document, to whom, and when. Written against the
     * acting user directly (not auth()) so it works from the API and MCP too.
     *
     * @param  array<int, string>  $cc
     */
    public static function logSend(
        Model $subject,
        User $actor,
        string $action,
        string $description,
        string $to,
        array $cc,
        Carbon $sentAt,
    ): ActivityLog {
        return ActivityLog::create([
            'organization_id' => $subject->getAttribute('organization_id'),
            'user_id' => $actor->id,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'description' => $description,
            'properties' => [
                'to' => $to,
                'cc' => $cc,
                'sent_at' => $sentAt->toIso8601String(),
                'sent_by' => $actor->name,
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
