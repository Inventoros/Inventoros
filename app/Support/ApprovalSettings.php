<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderApprovalStatus;
use App\Models\Auth\Organization;

/**
 * An organization's approval workflow settings, read from the
 * `approvals` key of organizations.settings.
 *
 * Every workflow is off unless the organization turns it on, so existing
 * organizations keep working exactly as before. A null threshold means
 * "every request needs approval" once the workflow is on.
 */
final class ApprovalSettings
{
    public const KEY = 'approvals';

    public function __construct(
        public readonly bool $purchaseOrdersEnabled = false,
        public readonly ?float $purchaseOrdersThreshold = null,
        public readonly bool $stockAdjustmentsEnabled = false,
        public readonly ?int $stockAdjustmentsQuantityThreshold = null,
        public readonly ?float $stockAdjustmentsValueThreshold = null,
        public readonly bool $stockTransfersEnabled = false,
        public readonly bool $adminsCanSelfApprove = true,
        public readonly bool $ordersEnabled = false,
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
            purchaseOrdersEnabled: (bool) ($raw['purchase_orders_enabled'] ?? false),
            purchaseOrdersThreshold: self::float($raw['purchase_orders_threshold'] ?? null),
            stockAdjustmentsEnabled: (bool) ($raw['stock_adjustments_enabled'] ?? false),
            stockAdjustmentsQuantityThreshold: self::int($raw['stock_adjustments_quantity_threshold'] ?? null),
            stockAdjustmentsValueThreshold: self::float($raw['stock_adjustments_value_threshold'] ?? null),
            stockTransfersEnabled: (bool) ($raw['stock_transfers_enabled'] ?? false),
            adminsCanSelfApprove: (bool) ($raw['admins_can_self_approve'] ?? true),
            ordersEnabled: (bool) ($raw['orders_enabled'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'purchase_orders_enabled' => $this->purchaseOrdersEnabled,
            'purchase_orders_threshold' => $this->purchaseOrdersThreshold,
            'stock_adjustments_enabled' => $this->stockAdjustmentsEnabled,
            'stock_adjustments_quantity_threshold' => $this->stockAdjustmentsQuantityThreshold,
            'stock_adjustments_value_threshold' => $this->stockAdjustmentsValueThreshold,
            'stock_transfers_enabled' => $this->stockTransfersEnabled,
            'admins_can_self_approve' => $this->adminsCanSelfApprove,
            'orders_enabled' => $this->ordersEnabled,
        ];
    }

    /**
     * The approval status a new sales order starts in: pending when the
     * organization requires order approval, otherwise not required.
     */
    public function initialOrderApprovalStatus(): OrderApprovalStatus
    {
        return $this->ordersEnabled ? OrderApprovalStatus::PENDING : OrderApprovalStatus::NOT_REQUIRED;
    }

    /**
     * A purchase order needs approval when the workflow is on and its total
     * is at or above the threshold (or there is no threshold).
     */
    public function purchaseOrderNeedsApproval(float $total): bool
    {
        if (! $this->purchaseOrdersEnabled) {
            return false;
        }

        return $this->purchaseOrdersThreshold === null || $total >= $this->purchaseOrdersThreshold;
    }

    /**
     * A manual adjustment needs approval when the workflow is on and either
     * threshold is exceeded. With no thresholds set, every one does.
     */
    public function stockAdjustmentNeedsApproval(int $quantity, ?float $value): bool
    {
        if (! $this->stockAdjustmentsEnabled) {
            return false;
        }

        $qtyLimit = $this->stockAdjustmentsQuantityThreshold;
        $valueLimit = $this->stockAdjustmentsValueThreshold;

        if ($qtyLimit === null && $valueLimit === null) {
            return true;
        }

        if ($qtyLimit !== null && abs($quantity) > $qtyLimit) {
            return true;
        }

        return $valueLimit !== null && $value !== null && $value > $valueLimit;
    }

    private static function float(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
