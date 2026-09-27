<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission;
use App\Exceptions\ApprovalException;
use App\Exceptions\InsufficientStockException;
use App\Models\ActivityLog;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAdjustmentRequest;
use App\Models\Inventory\StockTransfer;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Support\ApprovalSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The approval workflows for purchase orders, manual stock adjustments and
 * stock transfers.
 *
 * Each workflow is off until an organization turns it on (see
 * ApprovalSettings). The web UI, the REST API, the MCP tools and GraphQL all
 * go through this one service, so the rules are the same everywhere:
 *
 *  - only a user with the matching approve_* permission can decide;
 *  - nobody decides on their own request, except an admin when the
 *    organization allows it (the default);
 *  - a request is decided once, under a row lock;
 *  - a held stock adjustment only moves stock on approval, through
 *    StockAdjustment::adjust()/adjustVariant() with negative stock re-checked.
 */
final class ApprovalService
{
    public const PURCHASE_ORDER = 'purchase_order';

    public const STOCK_ADJUSTMENT = 'stock_adjustment';

    public const STOCK_TRANSFER = 'stock_transfer';

    public const TYPES = [self::PURCHASE_ORDER, self::STOCK_ADJUSTMENT, self::STOCK_TRANSFER];

    /**
     * @var array<string, Permission>
     */
    private const PERMISSIONS = [
        self::PURCHASE_ORDER => Permission::APPROVE_PURCHASE_ORDERS,
        self::STOCK_ADJUSTMENT => Permission::APPROVE_STOCK_ADJUSTMENTS,
        self::STOCK_TRANSFER => Permission::APPROVE_STOCK_TRANSFERS,
    ];

    public static function permissionFor(string $type): Permission
    {
        return self::PERMISSIONS[$type] ?? throw ApprovalException::notFound("Unknown approval type '{$type}'.");
    }

    // ==================== PURCHASE ORDERS ====================

    /**
     * Put a draft purchase order in front of the approvers.
     *
     * @throws ApprovalException when the PO does not need approval or is not in a state to be submitted
     */
    public function submitPurchaseOrder(PurchaseOrder $purchaseOrder, User $user): PurchaseOrder
    {
        $this->assertSameOrganization($purchaseOrder, $user);

        $purchaseOrder = DB::transaction(function () use ($purchaseOrder, $user) {
            $po = PurchaseOrder::withoutGlobalScope(OrganizationScope::class)->whereKey($purchaseOrder->getKey())->lockForUpdate()->firstOrFail();

            if ($po->status === PurchaseOrder::STATUS_DRAFT && ! $po->needsApproval()) {
                throw ApprovalException::notRequired("Purchase order {$po->po_number} does not need approval and can be sent as it is.");
            }

            if (! $po->canBeSubmittedForApproval()) {
                throw ApprovalException::invalidState(match (true) {
                    $po->approval_status === PurchaseOrder::APPROVAL_PENDING => "Purchase order {$po->po_number} is already waiting for approval.",
                    $po->approval_status === PurchaseOrder::APPROVAL_APPROVED => "Purchase order {$po->po_number} is already approved.",
                    $po->status !== PurchaseOrder::STATUS_DRAFT => "Only draft purchase orders can be submitted for approval.",
                    default => "Add at least one item before submitting purchase order {$po->po_number} for approval.",
                });
            }

            $po->forceFill([
                'approval_status' => PurchaseOrder::APPROVAL_PENDING,
                'approval_requested_by' => $user->id,
                'approval_requested_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'approval_notes' => null,
            ])->save();

            $this->log($po, $user, 'approval_requested', "Submitted purchase order {$po->po_number} for approval");

            return $po;
        });

        DB::afterCommit(fn () => $this->notifyApprovers(self::PURCHASE_ORDER, $purchaseOrder, $user));

        return $purchaseOrder;
    }

    // ==================== STOCK TRANSFERS ====================

    /**
     * Hold a newly created transfer for approval when the organization
     * requires it. A no-op otherwise.
     */
    public function holdTransferIfRequired(StockTransfer $transfer, User $user): StockTransfer
    {
        if (! ApprovalSettings::forOrganization($transfer->organization_id)->stockTransfersEnabled) {
            return $transfer;
        }

        $transfer->forceFill([
            'approval_status' => StockTransfer::APPROVAL_PENDING,
            'approval_requested_by' => $user->id,
            'approval_requested_at' => now(),
        ])->save();

        $this->log($transfer, $user, 'approval_requested', "Transfer {$transfer->transfer_number} is waiting for approval");

        DB::afterCommit(fn () => $this->notifyApprovers(self::STOCK_TRANSFER, $transfer, $user));

        return $transfer;
    }

    /**
     * @throws ApprovalException when the transfer may not ship or complete yet
     */
    public function assertTransferMayProceed(StockTransfer $transfer): void
    {
        if ($transfer->approval_status === StockTransfer::APPROVAL_PENDING) {
            throw ApprovalException::invalidState("Transfer {$transfer->transfer_number} is waiting for approval.");
        }

        if ($transfer->approval_status === StockTransfer::APPROVAL_REJECTED) {
            throw ApprovalException::invalidState("Transfer {$transfer->transfer_number} was rejected.");
        }
    }

    // ==================== STOCK ADJUSTMENTS ====================

    /**
     * The money value of an adjustment: |quantity| x unit cost, using the
     * variant's cost for a variant and falling back to the selling price
     * when no purchase price is recorded.
     */
    public function adjustmentValue(Product $product, ?ProductVariant $variant, int $quantity): ?float
    {
        $unit = $variant
            ? ($variant->purchase_price ?? $variant->price ?? $product->purchase_price ?? $product->price)
            : ($product->purchase_price ?? $product->price);

        return $unit === null ? null : round(abs($quantity) * (float) $unit, 2);
    }

    public function stockAdjustmentNeedsApproval(Product $product, ?ProductVariant $variant, int $quantity): bool
    {
        return ApprovalSettings::forOrganization($product->organization_id)
            ->stockAdjustmentNeedsApproval($quantity, $this->adjustmentValue($product, $variant, $quantity));
    }

    /**
     * Make a manual stock adjustment, or hold it for approval when the
     * organization's rules say it needs one.
     *
     * @return StockAdjustment|StockAdjustmentRequest the ledger row when applied, the request when held
     *
     * @throws InsufficientStockException when the change would take stock below zero
     */
    public function submitStockAdjustment(
        User $user,
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        string $type,
        ?string $reason = null,
        ?string $notes = null,
        ?int $locationId = null,
    ): StockAdjustment|StockAdjustmentRequest {
        if ($this->stockAdjustmentNeedsApproval($product, $variant, $quantity)) {
            return $this->requestStockAdjustment($user, $product, $variant, $quantity, $type, $reason, $notes, $locationId);
        }

        return $this->applyAdjustment($product, $variant, $quantity, $type, $reason, $notes, $locationId, $user, null);
    }

    /**
     * Hold a manual stock adjustment for approval. Stock is not touched.
     *
     * @throws InsufficientStockException when the change would already take stock below zero
     */
    public function requestStockAdjustment(
        User $user,
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        string $type,
        ?string $reason = null,
        ?string $notes = null,
        ?int $locationId = null,
    ): StockAdjustmentRequest {
        // Refuse up front what could never be approved, so the requester
        // hears it now rather than from the approver later. Approval checks
        // again under the row lock.
        $onHand = (int) ($variant?->stock ?? $product->stock);
        if ($quantity < 0 && $onHand + $quantity < 0) {
            throw new InsufficientStockException('Cannot remove '.abs($quantity)." units; only {$onHand} on hand.");
        }

        $request = DB::transaction(function () use ($user, $product, $variant, $quantity, $type, $reason, $notes, $locationId) {
            $request = StockAdjustmentRequest::create([
                'organization_id' => $product->organization_id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'location_id' => $locationId,
                'requested_by' => $user->id,
                'type' => $type,
                'quantity' => $quantity,
                'value' => $this->adjustmentValue($product, $variant, $quantity),
                'reason' => $reason,
                'notes' => $notes,
                'status' => StockAdjustmentRequest::STATUS_PENDING,
            ]);

            $label = $this->productLabel($product, $variant);
            $this->log($request, $user, 'approval_requested', "Requested a stock adjustment of {$this->signed($quantity)} for {$label}");

            return $request;
        });

        DB::afterCommit(fn () => $this->notifyApprovers(self::STOCK_ADJUSTMENT, $request, $user));

        return $request;
    }

    // ==================== DECISIONS ====================

    /**
     * @throws ApprovalException
     */
    public function approve(User $user, string $type, int $id, ?string $notes = null): Model
    {
        return $this->decide($user, $type, $id, true, $notes);
    }

    /**
     * @throws ApprovalException
     */
    public function reject(User $user, string $type, int $id, string $notes): Model
    {
        return $this->decide($user, $type, $id, false, $notes);
    }

    /**
     * Whether $user may decide on this request (permission plus the
     * self-approval rule). Never throws.
     */
    public function canDecide(User $user, string $type, Model $subject): bool
    {
        try {
            $this->authorizeDecision($user, $type, $subject);

            return true;
        } catch (ApprovalException) {
            return false;
        }
    }

    private function decide(User $user, string $type, int $id, bool $approve, ?string $notes): Model
    {
        $subject = $this->find($user, $type, $id);
        $this->authorizeDecision($user, $type, $subject);

        $status = $approve ? 'approved' : 'rejected';

        $decided = DB::transaction(function () use ($user, $type, $subject, $approve, $notes, $status) {
            /** @var PurchaseOrder|StockTransfer|StockAdjustmentRequest $locked */
            $locked = $subject::withoutGlobalScope(OrganizationScope::class)->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();

            if ($this->approvalStatus($type, $locked) !== 'pending') {
                throw ApprovalException::invalidState('This request has already been decided.');
            }

            $decision = [
                'approved_by' => $user->id,
                'approved_at' => now(),
                'approval_notes' => $notes,
            ];

            if ($type === self::STOCK_ADJUSTMENT) {
                if ($approve) {
                    $decision['stock_adjustment_id'] = $this->applyHeldAdjustment($locked)->id;
                }
                $locked->forceFill($decision + ['status' => $status])->save();
            } else {
                $decision['approval_status'] = $status;
                if ($type === self::STOCK_TRANSFER && ! $approve) {
                    // A rejected transfer is not going anywhere.
                    $decision['status'] = 'cancelled';
                }
                $locked->forceFill($decision)->save();
            }

            $summary = $this->describe($type, $locked);
            $this->log(
                $locked,
                $user,
                $status,
                ucfirst($status)." {$summary['title']}".($notes ? ": {$notes}" : ''),
                ['notes' => $notes],
            );

            return $locked;
        });

        DB::afterCommit(fn () => $this->notifyRequester($type, $decided, $user, $status, $notes));

        return $decided;
    }

    /**
     * Apply a held adjustment through the audited ledger path, under the
     * same row lock a direct adjustment takes, re-checking negative stock
     * against what is on hand now. Attributed to the requester; the
     * approver is recorded on the request, which the ledger row references.
     */
    private function applyHeldAdjustment(StockAdjustmentRequest $request): StockAdjustment
    {
        $product = Product::withoutGlobalScope(OrganizationScope::class)->findOrFail($request->product_id);
        $variant = $request->product_variant_id
            ? ProductVariant::withoutGlobalScope(OrganizationScope::class)->findOrFail($request->product_variant_id)
            : null;

        try {
            return $this->applyAdjustment(
                $product,
                $variant,
                $request->quantity,
                $request->type,
                $request->reason,
                $request->notes,
                $request->location_id,
                $request->requested_by ? User::find($request->requested_by) : null,
                $request,
            );
        } catch (InsufficientStockException $e) {
            throw ApprovalException::insufficientStock('Cannot approve this adjustment now: '.lcfirst($e->getMessage()));
        }
    }

    private function applyAdjustment(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        string $type,
        ?string $reason,
        ?string $notes,
        ?int $locationId,
        ?User $actor,
        ?Model $reference,
    ): StockAdjustment {
        if ($variant) {
            return StockAdjustment::adjustVariant(
                variant: $variant,
                quantity: $quantity,
                type: $type,
                reason: $reason,
                notes: $notes,
                reference: $reference,
                allowNegative: false,
                actor: $actor,
            );
        }

        return StockAdjustment::adjust(
            product: $product,
            quantity: $quantity,
            type: $type,
            reason: $reason,
            notes: $notes,
            reference: $reference,
            allowNegative: false,
            actor: $actor,
            locationId: $locationId,
        );
    }

    /**
     * @throws ApprovalException
     */
    private function authorizeDecision(User $user, string $type, Model $subject): void
    {
        if (! $user->hasPermission(self::permissionFor($type))) {
            throw ApprovalException::forbidden();
        }

        if (in_array($user->id, $this->requesterIds($type, $subject), true)) {
            $allowed = $user->isAdmin()
                && ApprovalSettings::forOrganization($subject->getAttribute('organization_id'))->adminsCanSelfApprove;

            if (! $allowed) {
                throw ApprovalException::selfApproval();
            }
        }
    }

    /**
     * Everyone who counts as having asked for this: the submitter and, for a
     * PO or transfer, whoever created it.
     *
     * @return array<int, int>
     */
    private function requesterIds(string $type, Model $subject): array
    {
        $ids = match ($type) {
            self::PURCHASE_ORDER => [$subject->approval_requested_by, $subject->created_by],
            self::STOCK_TRANSFER => [$subject->approval_requested_by, $subject->transferred_by],
            self::STOCK_ADJUSTMENT => [$subject->requested_by],
        };

        return array_values(array_map('intval', array_filter($ids)));
    }

    private function requesterId(string $type, Model $subject): ?int
    {
        return match ($type) {
            self::PURCHASE_ORDER => $subject->approval_requested_by ?? $subject->created_by,
            self::STOCK_TRANSFER => $subject->approval_requested_by ?? $subject->transferred_by,
            self::STOCK_ADJUSTMENT => $subject->requested_by,
        };
    }

    private function approvalStatus(string $type, Model $subject): ?string
    {
        return $type === self::STOCK_ADJUSTMENT ? $subject->status : $subject->approval_status;
    }

    /**
     * Resolve a request inside the user's organization.
     *
     * @throws ApprovalException when it does not exist there
     */
    public function find(User $user, string $type, int $id): Model
    {
        $model = match ($type) {
            self::PURCHASE_ORDER => PurchaseOrder::class,
            self::STOCK_ADJUSTMENT => StockAdjustmentRequest::class,
            self::STOCK_TRANSFER => StockTransfer::class,
            default => throw ApprovalException::notFound("Unknown approval type '{$type}'."),
        };

        $subject = $model::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $user->organization_id)
            ->find($id);

        return $subject ?? throw ApprovalException::notFound();
    }

    // ==================== LISTING ====================

    /**
     * Everything waiting for a decision that $user is allowed to make.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingFor(User $user): Collection
    {
        $items = collect();

        foreach (self::TYPES as $type) {
            if (! $user->hasPermission(self::permissionFor($type))) {
                continue;
            }

            foreach ($this->pendingQuery($type, $user->organization_id)->get() as $subject) {
                if ($this->canDecide($user, $type, $subject)) {
                    $items->push($this->describe($type, $subject) + ['can_decide' => true]);
                }
            }
        }

        return $items->sortBy('requested_at')->values();
    }

    public function pendingCountFor(User $user): int
    {
        return $this->pendingFor($user)->count();
    }

    /**
     * $user's own requests: the open ones, then recent decisions.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function requestedBy(User $user, int $limit = 25): Collection
    {
        $orgId = $user->organization_id;

        $pos = PurchaseOrder::withoutGlobalScope(OrganizationScope::class)->with(['supplier', 'approvalRequester', 'approver'])
            ->where('organization_id', $orgId)->where('approval_requested_by', $user->id)
            ->whereNotNull('approval_status')->latest('approval_requested_at')->limit($limit)->get();
        $transfers = StockTransfer::withoutGlobalScope(OrganizationScope::class)->with(['fromLocation', 'toLocation', 'transferredBy', 'approver'])->withCount('items')
            ->where('organization_id', $orgId)->where('approval_requested_by', $user->id)
            ->whereNotNull('approval_status')->latest('approval_requested_at')->limit($limit)->get();
        $adjustments = StockAdjustmentRequest::withoutGlobalScope(OrganizationScope::class)->with(['product', 'variant', 'requester', 'approver'])
            ->where('organization_id', $orgId)->where('requested_by', $user->id)
            ->latest()->limit($limit)->get();

        return $pos->map(fn ($s) => $this->describe(self::PURCHASE_ORDER, $s))
            ->concat($transfers->map(fn ($s) => $this->describe(self::STOCK_TRANSFER, $s)))
            ->concat($adjustments->map(fn ($s) => $this->describe(self::STOCK_ADJUSTMENT, $s)))
            ->sortBy([
                fn ($a, $b) => ($a['status'] === 'pending' ? 0 : 1) <=> ($b['status'] === 'pending' ? 0 : 1),
                fn ($a, $b) => strcmp((string) $b['requested_at'], (string) $a['requested_at']),
            ])
            ->take($limit)
            ->values();
    }

    private function pendingQuery(string $type, int $organizationId)
    {
        return match ($type) {
            self::PURCHASE_ORDER => PurchaseOrder::withoutGlobalScope(OrganizationScope::class)->with(['supplier', 'approvalRequester', 'creator'])
                ->where('organization_id', $organizationId)
                ->where('status', PurchaseOrder::STATUS_DRAFT)
                ->where('approval_status', PurchaseOrder::APPROVAL_PENDING),
            self::STOCK_TRANSFER => StockTransfer::withoutGlobalScope(OrganizationScope::class)->with(['fromLocation', 'toLocation', 'transferredBy'])->withCount('items')
                ->where('organization_id', $organizationId)
                ->where('approval_status', StockTransfer::APPROVAL_PENDING),
            self::STOCK_ADJUSTMENT => StockAdjustmentRequest::withoutGlobalScope(OrganizationScope::class)->with(['product', 'variant', 'requester', 'location'])
                ->where('organization_id', $organizationId)
                ->where('status', StockAdjustmentRequest::STATUS_PENDING),
        };
    }

    /**
     * A uniform, UI- and API-ready description of a request.
     *
     * @return array<string, mixed>
     */
    public function describe(string $type, Model $subject): array
    {
        $base = match ($type) {
            self::PURCHASE_ORDER => [
                'reference' => $subject->po_number,
                'title' => "purchase order {$subject->po_number}",
                'summary' => trim(($subject->supplier?->name ?? 'Unknown supplier').', '.($subject->currency ?? '').' '.number_format((float) $subject->total, 2)),
                'amount' => (float) $subject->total,
                'url' => route('purchase-orders.show', $subject->id),
                'requested_at' => $subject->approval_requested_at?->toIso8601String(),
                'requester' => ($subject->approvalRequester ?? $subject->creator)?->name,
            ],
            self::STOCK_TRANSFER => [
                'reference' => $subject->transfer_number,
                'title' => "transfer {$subject->transfer_number}",
                'summary' => ($subject->fromLocation?->name ?? '?').' to '.($subject->toLocation?->name ?? '?')
                    .', '.($subject->items_count ?? $subject->items()->count()).' line(s)',
                'amount' => null,
                'url' => route('stock-transfers.show', $subject->id),
                'requested_at' => $subject->approval_requested_at?->toIso8601String(),
                'requester' => $subject->transferredBy?->name,
            ],
            self::STOCK_ADJUSTMENT => [
                'reference' => 'ADJ-REQ-'.$subject->id,
                'title' => 'stock adjustment request ADJ-REQ-'.$subject->id,
                'summary' => $this->productLabel($subject->product, $subject->variant)
                    .': '.$this->signed($subject->quantity).' ('.$subject->type.')'
                    .($subject->reason ? ', '.$subject->reason : ''),
                'amount' => $subject->value !== null ? (float) $subject->value : null,
                'url' => route('approvals.index'),
                'requested_at' => $subject->created_at?->toIso8601String(),
                'requester' => $subject->requester?->name,
            ],
        };

        return [
            'type' => $type,
            'id' => $subject->id,
            'status' => $this->approvalStatus($type, $subject),
            'requested_by' => $this->requesterId($type, $subject),
            'approved_by' => $subject->approved_by,
            'approver' => $subject->relationLoaded('approver') ? $subject->approver?->name : null,
            'approved_at' => $subject->approved_at?->toIso8601String(),
            'notes' => $subject->approval_notes,
        ] + $base;
    }

    // ==================== NOTIFY / LOG ====================

    private function notifyApprovers(string $type, Model $subject, User $requester): void
    {
        $permission = self::permissionFor($type);

        $approvers = User::query()
            ->where('organization_id', $subject->getAttribute('organization_id'))
            ->whereKeyNot($requester->id)
            ->get()
            ->filter(fn (User $u) => $u->hasPermission($permission))
            ->values();

        NotificationService::createApprovalRequestedNotifications($this->describe($type, $subject->fresh() ?? $subject), $approvers, $requester);
    }

    private function notifyRequester(string $type, Model $subject, User $approver, string $status, ?string $notes): void
    {
        $requesterId = $this->requesterId($type, $subject);
        $requester = $requesterId ? User::find($requesterId) : null;

        if (! $requester) {
            return;
        }

        NotificationService::createApprovalDecisionNotification($this->describe($type, $subject), $requester, $approver, $status, $notes);
    }

    /**
     * Write the activity log row directly with the acting user, so API, MCP
     * and GraphQL calls are logged the same as web ones.
     *
     * @param  array<string, mixed>  $properties
     */
    private function log(Model $subject, User $actor, string $action, string $description, array $properties = []): void
    {
        ActivityLog::create([
            'organization_id' => $subject->getAttribute('organization_id'),
            'user_id' => $actor->id,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    private function assertSameOrganization(Model $subject, User $user): void
    {
        if ((int) $subject->getAttribute('organization_id') !== (int) $user->organization_id) {
            throw ApprovalException::notFound();
        }
    }

    private function productLabel(?Product $product, ?ProductVariant $variant): string
    {
        $name = $product?->name ?? 'Unknown product';

        return $variant ? "{$name} ({$variant->title})" : $name;
    }

    private function signed(int $quantity): string
    {
        return $quantity > 0 ? "+{$quantity}" : (string) $quantity;
    }
}
