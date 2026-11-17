<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\ExchangeRates\PurchaseOrderExchangeRates;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The single implementation of purchase-order create, update and receive.
 *
 * Shared by the web PurchaseOrderController, the REST API and the GraphQL
 * mutations so the exact-decimal totals, PO numbering retry and the locked
 * receive path cannot drift between surfaces.
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly WarehouseAccessService $warehouseAccess,
        private readonly PurchaseOrderExchangeRates $exchangeRates,
    ) {}

    /**
     * Create a draft purchase order with its line items.
     *
     * In a currency other than the organization's, the PO takes the
     * exchange rate for its order date (see PurchaseOrderExchangeRates);
     * `exchange_rate_mode` / `exchange_rate` let a permitted user enter or
     * confirm one. No available rate never stops the draft being saved.
     *
     * @param  array{supplier_id: int, order_date: string, expected_date?: string|null, currency: string, shipping?: numeric|null, tax?: numeric|null, notes?: string|null, exchange_rate_mode?: string|null, exchange_rate?: numeric|null, items: array<int, array{product_id: int, product_variant_id?: int|null, quantity: int, unit_cost: numeric, supplier_sku?: string|null}>}  $data
     */
    public function create(int $organizationId, User $creator, array $data): PurchaseOrder
    {
        // Verify supplier belongs to organization
        Supplier::forOrganization($organizationId)->findOrFail($data['supplier_id']);

        [$subtotal, $orderItems] = $this->buildNewItems($organizationId, $data['items']);

        // Before the transaction: finding the rate may ask the provider.
        $snapshot = $this->exchangeRates->snapshotFor($organizationId, (string) $data['currency'], $data['order_date'], $data, null, $creator) ?? [];

        // Generate the PO number and insert the order + items atomically with a
        // retry, so a concurrent create in the same tenant/day can't collide on
        // po_number and 500.
        return PurchaseOrder::createWithNumber($organizationId, function (string $poNumber) use ($organizationId, $data, $creator, $subtotal, $orderItems, $snapshot) {
            $po = new PurchaseOrder([
                'organization_id' => $organizationId,
                'supplier_id' => $data['supplier_id'],
                'created_by' => $creator->id,
                'po_number' => $poNumber,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'subtotal' => $subtotal,
                'tax' => $data['tax'] ?? 0,
                'shipping' => $data['shipping'] ?? 0,
                'total' => Money::add($subtotal, $data['tax'] ?? 0, $data['shipping'] ?? 0),
                'currency' => $data['currency'],
                'notes' => $data['notes'] ?? null,
            ]);
            $po->forceFill($snapshot)->save();

            $po->items()->createMany($orderItems);

            return $po;
        });
    }

    /**
     * Update an editable purchase order. Header fields present in $data are
     * applied; when `items` is present the line items are reconciled (lines
     * with a known `id` are updated, others created, missing ones deleted)
     * and the subtotal recomputed. The total is recomputed on every edit.
     *
     * The exchange rate follows the currency and order date (see
     * PurchaseOrderExchangeRates); `exchange_rate_mode` / `exchange_rate`
     * let $actor (when permitted) enter, confirm or refresh it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidStateException when the PO is no longer editable
     */
    public function update(PurchaseOrder $purchaseOrder, int $organizationId, array $data, ?User $actor = null): PurchaseOrder
    {
        if (! $purchaseOrder->canBeEdited()) {
            throw new InvalidStateException('This purchase order cannot be edited', 'cannot_edit');
        }

        // Before the transaction: finding the rate may ask the provider.
        $snapshot = $this->exchangeRates->snapshotFor(
            $organizationId,
            (string) ($data['currency'] ?? $purchaseOrder->currency),
            $data['order_date'] ?? null,
            $data,
            $purchaseOrder,
            $actor ?? (auth()->user() instanceof User ? auth()->user() : null),
        );
        unset($data['exchange_rate'], $data['exchange_rate_mode']);

        DB::transaction(function () use (&$purchaseOrder, $organizationId, &$data, $snapshot) {
            // Decide on the row as it is now, under a lock that send and the
            // approval decision also take. A copy loaded before a concurrent
            // send or submission must not edit what was sent or submitted.
            $purchaseOrder = PurchaseOrder::withoutGlobalScope(OrganizationScope::class)
                ->whereKey($purchaseOrder->getKey())
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $purchaseOrder->canBeEdited()) {
                throw new InvalidStateException('This purchase order cannot be edited', 'cannot_edit');
            }

            if (isset($data['items'])) {
                $purchaseOrder->load('items');
                $existingItems = $purchaseOrder->items->keyBy('id');
                $itemIdsToKeep = [];
                $subtotal = '0';
                $newItems = [];

                foreach ($data['items'] as $itemData) {
                    $product = Product::forOrganization($organizationId)->findOrFail($itemData['product_id']);
                    $variant = PurchaseOrderItem::resolveVariant($product, $itemData['product_variant_id'] ?? null);
                    $itemSubtotal = Money::multiply($itemData['unit_cost'], $itemData['quantity']);
                    $subtotal = Money::add($subtotal, $itemSubtotal);

                    if (! empty($itemData['id']) && $existingItems->has($itemData['id'])) {
                        $existingItems->get($itemData['id'])->update([
                            'product_id' => $itemData['product_id'],
                            'product_variant_id' => $variant?->id,
                            'product_name' => $product->name,
                            'sku' => $variant?->sku ?? $product->sku,
                            'supplier_sku' => $itemData['supplier_sku'] ?? null,
                            'quantity_ordered' => $itemData['quantity'],
                            'unit_cost' => $itemData['unit_cost'],
                            'subtotal' => $itemSubtotal,
                            'total' => $itemSubtotal,
                        ]);
                        $itemIdsToKeep[] = $itemData['id'];
                    } else {
                        $newItems[] = $this->newItemRow($product, $variant, $itemData, $itemSubtotal);
                    }
                }

                $existingItems->filter(fn ($item) => ! in_array($item->id, $itemIdsToKeep))->each->delete();

                if (! empty($newItems)) {
                    $purchaseOrder->items()->createMany($newItems);
                }

                $data['subtotal'] = $subtotal;
            }

            unset($data['items'], $data['total']);

            // A cleared charge means none, not NULL (the columns are NOT NULL).
            foreach (['tax', 'shipping'] as $charge) {
                if (array_key_exists($charge, $data) && $data[$charge] === null) {
                    $data[$charge] = 0;
                }
            }

            // Always recompute the total from the final subtotal/tax/shipping,
            // whichever of them this edit touched. The approval threshold is
            // judged on the stored total, so a header-only edit (shipping or
            // tax without lines) must move it too; otherwise a small PO could
            // carry an arbitrary charge and still send without approval. A
            // changed total also clears a previous approval (model hook).
            $data['total'] = Money::add(
                $data['subtotal'] ?? $purchaseOrder->subtotal,
                $data['tax'] ?? $purchaseOrder->tax,
                $data['shipping'] ?? $purchaseOrder->shipping,
            );

            $purchaseOrder->fill($data);
            if ($snapshot !== null) {
                $purchaseOrder->forceFill($snapshot);
            }
            $purchaseOrder->save();
        });

        return $purchaseOrder->fresh();
    }

    /**
     * Receive quantities against a sent/partial purchase order.
     *
     * @param  array<int, array{id: int, quantity_to_receive: int, receiving_location_id?: int|null}>  $items
     * @return int the number of lines that received stock
     *
     * @throws InvalidStateException when the PO cannot receive items
     * @throws ValidationException when a requested line's product or variant is unavailable
     */
    public function receive(PurchaseOrder $purchaseOrder, User $actor, array $items): int
    {
        if ((int) $actor->organization_id !== (int) $purchaseOrder->organization_id) {
            throw new AuthorizationException('You do not have access to this purchase order.');
        }

        // Direct PHP callers share the same destination shape checks as the
        // web and API surfaces. Null means use the existing destination.
        Validator::make(['items' => $items], [
            'items.*.receiving_location_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        if (! $purchaseOrder->canReceiveItems()) {
            throw new InvalidStateException('This purchase order cannot receive items.', 'cannot_receive');
        }

        $receivedCount = 0;

        DB::transaction(function () use ($items, $purchaseOrder, $actor, &$receivedCount) {
            // Re-read the PO under a row lock so two concurrent receive calls
            // (a double-submit, a retried timeout, or two integration workers)
            // serialize here; the second observes the post-receive
            // status/quantities instead of the same stale pre-image, which
            // otherwise let both book stock and over-receive up to 2x. Each
            // item is likewise locked before receive().
            $po = PurchaseOrder::whereKey($purchaseOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! $po->canReceiveItems()) {
                throw new InvalidStateException('This purchase order cannot receive items.', 'cannot_receive');
            }

            foreach ($items as $index => $itemData) {
                if ($itemData['quantity_to_receive'] > 0) {
                    $item = PurchaseOrderItem::where('id', $itemData['id'])
                        ->where('purchase_order_id', $po->id)
                        ->lockForUpdate()
                        ->first();

                    if ($item && $item->remaining_quantity > 0) {
                        $override = isset($itemData['receiving_location_id']) ? (int) $itemData['receiving_location_id'] : null;
                        try {
                            $locationId = $item->receivingLocationId($override);
                        } catch (InvalidStateException $exception) {
                            if ($override === null) {
                                throw $exception;
                            }
                            throw ValidationException::withMessages([
                                "items.{$index}.receiving_location_id" => $exception->getMessage(),
                            ]);
                        }
                        // Check the actual receipt bin while the PO and line
                        // are locked, rather than authorizing a stale default
                        // before the transaction starts.
                        $this->warehouseAccess->authorizeLocation($actor, $locationId);

                        if ($item->receive((int) $itemData['quantity_to_receive'], $override) === null) {
                            // A product or variant may have been deleted after
                            // this PO was sent. Refuse the whole delivery so a
                            // skipped line cannot be reported as received, or
                            // leave earlier lines silently partially booked.
                            throw ValidationException::withMessages([
                                'items' => "The product or variant for {$item->product_name} is no longer available. Restore it before receiving this line.",
                            ]);
                        }
                        $receivedCount++;
                    }
                }
            }
        });

        return $receivedCount;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function buildNewItems(int $organizationId, array $items): array
    {
        // Calculate order totals with exact-decimal math rather than
        // accumulating float rounding error.
        $subtotal = '0';
        $orderItems = [];

        foreach ($items as $item) {
            $product = Product::forOrganization($organizationId)->findOrFail($item['product_id']);
            $variant = PurchaseOrderItem::resolveVariant($product, $item['product_variant_id'] ?? null);
            $itemSubtotal = Money::multiply($item['unit_cost'], $item['quantity']);
            $subtotal = Money::add($subtotal, $itemSubtotal);

            $orderItems[] = $this->newItemRow($product, $variant, $item, $itemSubtotal);
        }

        return [$subtotal, $orderItems];
    }

    /**
     * @param  array<string, mixed>  $itemData
     * @return array<string, mixed>
     */
    private function newItemRow(Product $product, ?ProductVariant $variant, array $itemData, string $itemSubtotal): array
    {
        return [
            'product_id' => $itemData['product_id'],
            'product_variant_id' => $variant?->id,
            'product_name' => $product->name,
            'sku' => $variant?->sku ?? $product->sku,
            'supplier_sku' => $itemData['supplier_sku'] ?? null,
            'quantity_ordered' => $itemData['quantity'],
            'quantity_received' => 0,
            'unit_cost' => $itemData['unit_cost'],
            'subtotal' => $itemSubtotal,
            'tax' => 0,
            'total' => $itemSubtotal,
        ];
    }
}
