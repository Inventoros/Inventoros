<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The single implementation of purchase-order create, update and receive.
 *
 * Shared by the web PurchaseOrderController, the REST API and the GraphQL
 * mutations so the exact-decimal totals, PO numbering retry and the locked
 * receive path cannot drift between surfaces.
 */
final class PurchaseOrderService
{
    public function __construct(private readonly WarehouseAccessService $warehouseAccess) {}

    /**
     * Create a draft purchase order with its line items.
     *
     * @param  array{supplier_id: int, order_date: string, expected_date?: string|null, currency: string, shipping?: numeric|null, tax?: numeric|null, notes?: string|null, items: array<int, array{product_id: int, product_variant_id?: int|null, quantity: int, unit_cost: numeric, supplier_sku?: string|null}>}  $data
     */
    public function create(int $organizationId, User $creator, array $data): PurchaseOrder
    {
        // Verify supplier belongs to organization
        Supplier::forOrganization($organizationId)->findOrFail($data['supplier_id']);

        [$subtotal, $orderItems] = $this->buildNewItems($organizationId, $data['items']);

        // Generate the PO number and insert the order + items atomically with a
        // retry, so a concurrent create in the same tenant/day can't collide on
        // po_number and 500.
        return PurchaseOrder::createWithNumber($organizationId, function (string $poNumber) use ($organizationId, $data, $creator, $subtotal, $orderItems) {
            $po = PurchaseOrder::create([
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

            $po->items()->createMany($orderItems);

            return $po;
        });
    }

    /**
     * Update an editable purchase order. Header fields present in $data are
     * applied; when `items` is present the line items are reconciled (lines
     * with a known `id` are updated, others created, missing ones deleted)
     * and the totals recomputed.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidStateException when the PO is no longer editable
     */
    public function update(PurchaseOrder $purchaseOrder, int $organizationId, array $data): PurchaseOrder
    {
        if (! $purchaseOrder->canBeEdited()) {
            throw new InvalidStateException('This purchase order cannot be edited', 'cannot_edit');
        }

        DB::transaction(function () use ($purchaseOrder, $organizationId, &$data) {
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
                $data['total'] = Money::add($subtotal, $data['tax'] ?? $purchaseOrder->tax, $data['shipping'] ?? $purchaseOrder->shipping);
            }

            unset($data['items']);
            $purchaseOrder->update($data);
        });

        return $purchaseOrder->fresh();
    }

    /**
     * Receive quantities against a sent/partial purchase order.
     *
     * @param  array<int, array{id: int, quantity_to_receive: int}>  $items
     * @return int the number of lines that received stock
     *
     * @throws InvalidStateException when the PO cannot receive items
     */
    public function receive(PurchaseOrder $purchaseOrder, User $actor, array $items): int
    {
        // Goods land in each product's primary location; a restricted user
        // can only book them into their own warehouses.
        $this->warehouseAccess->authorizeReceiving($actor, $purchaseOrder, $items);

        if (! $purchaseOrder->canReceiveItems()) {
            throw new InvalidStateException('This purchase order cannot receive items.', 'cannot_receive');
        }

        $receivedCount = 0;

        DB::transaction(function () use ($items, $purchaseOrder, &$receivedCount) {
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

            foreach ($items as $itemData) {
                if ($itemData['quantity_to_receive'] > 0) {
                    $item = PurchaseOrderItem::where('id', $itemData['id'])
                        ->where('purchase_order_id', $po->id)
                        ->lockForUpdate()
                        ->first();

                    if ($item && $item->remaining_quantity > 0) {
                        $item->receive($itemData['quantity_to_receive']);
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
