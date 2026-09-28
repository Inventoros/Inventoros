<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Exceptions\DocumentEmailException;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limits emailing documents (purchase orders, invoices) to arbitrary
 * addresses, so the feature cannot be used as an open mail relay through the
 * organization's (or the instance's) mail provider. Every surface (web, REST,
 * MCP) goes through the document email services, which call this first.
 *
 * Two budgets, both configurable in config/limits.php `document_emails`:
 *  - per user and organization, per minute
 *  - per organization, per day
 */
final class DocumentEmailThrottle
{
    /**
     * Count one document email for $actor, or refuse it when a budget is spent.
     *
     * @throws DocumentEmailException with reason RATE_LIMITED
     */
    public static function hit(User $actor, string $document): void
    {
        $orgId = (int) $actor->organization_id;
        $perMinute = max(1, (int) config('limits.document_emails.per_user_per_minute', 10));
        $perDay = max(1, (int) config('limits.document_emails.per_organization_per_day', 500));

        $userKey = "document-email:user:{$orgId}:{$actor->id}";
        $orgKey = "document-email:org:{$orgId}:".now()->toDateString();

        if (RateLimiter::tooManyAttempts($userKey, $perMinute)) {
            self::refuse($actor, $document, 'per_user_per_minute',
                'Too many documents emailed. Try again in '.RateLimiter::availableIn($userKey).' seconds.');
        }

        if (RateLimiter::tooManyAttempts($orgKey, $perDay)) {
            self::refuse($actor, $document, 'per_organization_per_day',
                "Too many documents emailed today. Your organization can email up to {$perDay} documents a day.");
        }

        RateLimiter::hit($userKey, 60);
        RateLimiter::hit($orgKey, 86400);
    }

    /**
     * @return never
     */
    private static function refuse(User $actor, string $document, string $limit, string $message): void
    {
        Log::warning('Document email rate limit reached', [
            'limit' => $limit,
            'document' => $document,
            'user_id' => $actor->id,
            'organization_id' => $actor->organization_id,
        ]);

        throw DocumentEmailException::rateLimited($message);
    }
}
