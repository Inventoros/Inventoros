<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use Carbon\CarbonImmutable;

/**
 * A paid plugin's runtime licence for one organization, as plugin_licence()
 * returns it.
 *
 * Statuses:
 *  - valid:   a signed entitlement covers today (or covered it when the
 *             marketplace was last reached and the offline grace period
 *             has not run out: $inGrace is then true).
 *  - expired: the entitlement ran out (a cancelled or lapsed subscription,
 *             or offline for longer than the grace period).
 *  - missing: the organization has no marketplace connection, or the
 *             connected account does not own the plugin.
 *  - unknown: nothing verifiable yet (never checked, no signing key
 *             configured, or a document that does not verify).
 *
 * Fail safe: only `valid` unlocks a plugin's paid features. Every other
 * status means the plugin keeps working read-only; it never blocks a core
 * page, hides core records or deletes its own data.
 */
final class PluginLicence
{
    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const MISSING = 'missing';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $slug,
        public readonly string $status,
        public readonly string $reason,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?CarbonImmutable $graceUntil = null,
        public readonly bool $inGrace = false,
        public readonly ?CarbonImmutable $checkedAt = null,
    ) {}

    public function isValid(): bool
    {
        return $this->status === self::VALID;
    }

    /**
     * Whether the plugin may write (sync, create, change its own records).
     * The same as isValid(); read-only use is always allowed.
     */
    public function allowsWrites(): bool
    {
        return $this->isValid();
    }

    /**
     * @return array{slug: string, status: string, reason: string, expires_at: string|null, grace_until: string|null, in_grace: bool, checked_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'status' => $this->status,
            'reason' => $this->reason,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'grace_until' => $this->graceUntil?->toIso8601String(),
            'in_grace' => $this->inGrace,
            'checked_at' => $this->checkedAt?->toIso8601String(),
        ];
    }
}
