<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;

/**
 * An organization's backorder settings, read from the `backorders` key of
 * organizations.settings.
 *
 * Backordering is off unless the organization turns it on, so existing
 * organizations keep hard-blocking short orders exactly as before. The
 * master switch gates whether a short line may be accepted at creation; it
 * does not gate reconciliation or the ship-together hold, which are driven
 * by the order's own backordered state so disabling the switch never
 * strands stock already on backorder.
 */
final class BackorderSettings
{
    public const KEY = 'backorders';

    public function __construct(
        public readonly bool $enabled = false,
        public readonly bool $defaultAllow = false,
    ) {}

    public static function forOrganization(Organization|int|null $organization): self
    {
        if ($organization === null) {
            return new self;
        }

        $model = $organization instanceof Organization
            ? $organization
            : Organization::query()->find($organization);

        return self::fromArray((array) (($model?->settings ?? [])[self::KEY] ?? []));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: (bool) ($raw['enabled'] ?? false),
            defaultAllow: (bool) ($raw['default_allow'] ?? false),
        );
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'default_allow' => $this->defaultAllow,
        ];
    }

    /**
     * Whether a short line for this product may be backordered at creation:
     * the master switch is on and the product is opted in, either directly
     * or by being a preorder (a release date still in the future).
     */
    public function allowsBackorderFor(Product $product): bool
    {
        return $this->enabled && ((bool) $product->allow_backorder || $product->isPreorder());
    }
}
