<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\VariantReplenishmentPolicy;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit variant/warehouse demand; never infer a SKU from a parent total. */
final class VariantReplenishmentService
{
    public function __construct(private readonly WarehouseAccessService $warehouseAccess) {}

    public static function rules(): array
    {
        return ['product_variant_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer'],
            'location_id' => ['required', 'integer'], 'supplier_id' => ['required', 'integer'],
            'reorder_point' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'target_stock' => ['required', 'integer', 'gt:reorder_point', 'max:2147483647'],
            'reorder_quantity' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'minimum_order_quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'], 'supplier_sku' => ['nullable', 'string', 'max:255'],
            'auto_reorder' => ['sometimes', 'boolean']];
    }

    public function save(User $actor, array $data, ?VariantReplenishmentPolicy $policy = null): VariantReplenishmentPolicy
    {
        $data = Validator::make($data, self::rules())->validate();
        abort_unless($actor->hasPermission('view_products') && $actor->hasPermission('edit_products'), 403);
        if ($data['auto_reorder'] ?? false) {
            abort_unless($actor->hasPermission('create_purchase_orders'), 403);
        }
        if ($policy !== null) {
            abort_unless((int) $policy->organization_id === (int) $actor->organization_id, 404);
            if ((int) $data['product_variant_id'] !== (int) $policy->product_variant_id || (int) $data['warehouse_id'] !== (int) $policy->warehouse_id) {
                throw ValidationException::withMessages(['product_variant_id' => 'Create a new policy to change its variant or warehouse.']);
            }
        }
        $org = (int) $actor->organization_id;
        $variant = ProductVariant::where('organization_id', $org)->where('is_active', true)->findOrFail($data['product_variant_id']);
        Product::forOrganization($org)->active()->where('has_variants', true)->where('type', '<>', 'kit')->findOrFail($variant->product_id);
        $warehouse = Warehouse::forOrganization($org)->where('is_active', true)->findOrFail($data['warehouse_id']);
        $this->warehouseAccess->authorizeWarehouse($actor, (int) $warehouse->id);
        ProductLocation::forOrganization($org)->active()->where('warehouse_id', $warehouse->id)->findOrFail($data['location_id']);
        $supplier = Supplier::forOrganization($org)->where('is_active', true)->findOrFail($data['supplier_id']);
        $currency = $supplier->currency ?: Organization::currencyFor($org);
        if (strtoupper($data['currency'] ?? '') !== strtoupper($currency)) {
            throw ValidationException::withMessages(['currency' => 'The supplier currency changed. Review the unit cost before saving.']);
        }

        return DB::transaction(function () use ($actor, $data, $org, $variant, $warehouse, $supplier, $currency, $policy): VariantReplenishmentPolicy {
            $saved = $policy ?? VariantReplenishmentPolicy::withTrashed()->firstOrCreate([
                'organization_id' => $org, 'product_variant_id' => $variant->id, 'warehouse_id' => $warehouse->id,
            ], ['location_id' => $data['location_id'], 'supplier_id' => $supplier->id, 'reorder_point' => $data['reorder_point'],
                'target_stock' => $data['target_stock'], 'unit_cost' => Money::of($data['unit_cost']), 'currency' => $currency]);
            $saved = VariantReplenishmentPolicy::withTrashed()->where('organization_id', $org)->whereKey($saved->id)->lockForUpdate()->firstOrFail();
            $old = $saved->getAttributes();
            $saved->fill(collect($data)->only(['location_id', 'reorder_point', 'target_stock', 'reorder_quantity', 'minimum_order_quantity', 'supplier_sku'])->all());
            $saved->fill(['supplier_id' => $supplier->id, 'unit_cost' => Money::of($data['unit_cost']), 'currency' => strtoupper($currency),
                'auto_reorder' => (bool) ($data['auto_reorder'] ?? false), 'managed_by' => $actor->id, 'deleted_at' => null])->save();
            $this->log($saved, $actor, 'replenishment_policy_saved', $old, $saved->getAttributes());

            return $saved;
        }, 3);
    }

    public function remove(User $actor, VariantReplenishmentPolicy $policy): void
    {
        abort_unless((int) $policy->organization_id === (int) $actor->organization_id
            && $actor->hasPermission('edit_products') && $actor->hasPermission('view_products'), 403);
        $this->warehouseAccess->authorizeWarehouse($actor, (int) $policy->warehouse_id);
        DB::transaction(function () use ($actor, $policy): void {
            $locked = VariantReplenishmentPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $locked->update(['auto_reorder' => false]);
            $locked->delete();
            $this->log($locked, $actor, 'replenishment_policy_removed', [], ['deleted' => true]);
        });
    }

    /** Cheap page-wide estimates; authoritative planning rechecks under locks. */
    public function forecasts(Collection $policies): array
    {
        if ($policies->isEmpty()) {
            return [];
        }
        $org = (int) $policies->first()->organization_id;
        $variants = $policies->pluck('product_variant_id')->unique()->all();
        $onHand = DB::table('variant_location_stocks as bin')->join('product_locations as location', 'location.id', '=', 'bin.location_id')
            ->where('bin.organization_id', $org)->where('location.organization_id', $org)->whereNull('location.deleted_at')
            ->whereIn('bin.product_variant_id', $variants)->groupBy('bin.product_variant_id', 'location.warehouse_id')
            ->selectRaw('bin.product_variant_id, location.warehouse_id, SUM(bin.quantity) as quantity')->get()
            ->keyBy(fn ($row) => $row->product_variant_id.':'.$row->warehouse_id);
        $incoming = DB::table('purchase_order_items as line')->join('purchase_orders as po', 'po.id', '=', 'line.purchase_order_id')
            ->join('product_variants as variant', 'variant.id', '=', 'line.product_variant_id')
            ->join('products as product', 'product.id', '=', 'variant.product_id')
            ->join('product_locations as location', 'location.id', '=', DB::raw('COALESCE(line.receiving_location_id, product.location_id)'))
            ->where('po.organization_id', $org)->where('variant.organization_id', $org)->where('product.organization_id', $org)
            ->where('location.organization_id', $org)->whereNull('po.deleted_at')->whereNull('location.deleted_at')
            ->whereColumn('line.product_id', 'product.id')
            ->whereIn('line.product_variant_id', $variants)->whereIn('po.status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL])
            ->where(fn ($q) => $q->whereNull('po.approval_status')->orWhere('po.approval_status', '<>', 'rejected'))
            ->whereColumn('line.quantity_ordered', '>', 'line.quantity_received')->groupBy('line.product_variant_id', 'location.warehouse_id')
            ->selectRaw('line.product_variant_id, location.warehouse_id, SUM(line.quantity_ordered - line.quantity_received) as quantity')->get()
            ->keyBy(fn ($row) => $row->product_variant_id.':'.$row->warehouse_id);

        return $policies->mapWithKeys(function ($policy) use ($onHand, $incoming): array {
            $key = $policy->product_variant_id.':'.$policy->warehouse_id;

            return [$policy->id => $this->quantities($policy, (int) ($onHand[$key]->quantity ?? 0), (int) ($incoming[$key]->quantity ?? 0))];
        })->all();
    }

    public function createDraft(User $actor, VariantReplenishmentPolicy $policy, bool $automatic = false): ?PurchaseOrder
    {
        abort_unless((int) $actor->organization_id === (int) $policy->organization_id
            && $actor->hasPermission('view_products') && $actor->hasPermission('create_purchase_orders'), 403);
        $this->warehouseAccess->authorizeWarehouse($actor, (int) $policy->warehouse_id);
        if ($automatic) {
            abort_unless($actor->hasPermission('edit_products'), 403);
        }
        try {
            // The document-number retry wraps the entire operation, so a
            // collision restarts both the snapshot and the policy check.
            return PurchaseOrder::createWithNumber((int) $actor->organization_id, function (string $number) use ($policy, $actor, $automatic): PurchaseOrder {
                $locked = VariantReplenishmentPolicy::where('organization_id', $actor->organization_id)->whereKey($policy->id)->lockForUpdate()->firstOrFail();
                if ($automatic && (! $locked->auto_reorder || (int) $locked->managed_by !== (int) $actor->id)) {
                    throw new InvalidStateException('The automatic policy was disabled or its responsible user changed.', 'replenishment_disabled');
                }
                // Match receiving's lock order: PO, PO line, product/variant,
                // then location/bin. Current locking reads also work inside
                // an older MySQL repeatable-read caller snapshot.
                $incoming = $this->incomingLocked($locked);
                [$product, $variant, $warehouse, $supplier, $locations] = $this->assetsLocked($locked);
                $this->warehouseAccess->authorizeWarehouse($actor, (int) $warehouse->id);
                $onHand = 0;
                foreach (DB::table('variant_location_stocks')->where('organization_id', $locked->organization_id)
                    ->where('product_variant_id', $variant->id)->whereIn('location_id', $locations)
                    ->select(['id', 'quantity'])->sharedLock()->lazyById(500) as $bin) {
                    $onHand += (int) $bin->quantity;
                }
                $pending = 0;
                foreach ($locations as $location) {
                    $pending += $incoming[$location] ?? 0;
                }
                if (in_array((int) $product->location_id, $locations, true)) {
                    $pending += $incoming[0] ?? 0;
                }
                $forecast = $this->quantities($locked, $onHand, $pending);
                if ($forecast['suggested_quantity'] === 0) {
                    throw new InvalidStateException('No replenishment is currently needed.', 'replenishment_not_due');
                }
                $quantity = $forecast['suggested_quantity'];
                $subtotal = Money::multiply($locked->unit_cost, $quantity);
                if (Money::compare($subtotal, '9999999999.99') > 0) {
                    throw ValidationException::withMessages(['unit_cost' => 'The order total exceeds the supported money range.']);
                }
                $po = PurchaseOrder::create(['organization_id' => $locked->organization_id, 'supplier_id' => $supplier->id,
                    'created_by' => $actor->id, 'po_number' => $number, 'status' => PurchaseOrder::STATUS_DRAFT,
                    'currency' => $locked->currency, 'order_date' => now(), 'subtotal' => $subtotal, 'tax' => 0, 'shipping' => 0, 'total' => $subtotal,
                    'notes' => 'Variant replenishment: '.$variant->sku.'; destination '.$warehouse->name]);
                PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
                    'product_name' => $product->name.' ('.$variant->title.')', 'sku' => $variant->sku, 'supplier_sku' => $locked->supplier_sku,
                    'quantity_ordered' => $quantity, 'quantity_received' => 0, 'unit_cost' => $locked->unit_cost,
                    'subtotal' => $subtotal, 'tax' => 0, 'total' => $subtotal,
                    'replenishment_policy_id' => $locked->id, 'receiving_location_id' => $locked->location_id]);
                $this->log($locked, $actor, 'variant_replenishment_ordered', [], $forecast + ['purchase_order_id' => $po->id]);

                return $po;
            }, transactionAttempts: 3);
        } catch (InvalidStateException $error) {
            if ($error->errorCode === 'replenishment_not_due') {
                return null;
            }
            throw $error;
        }
    }

    private function incomingLocked(VariantReplenishmentPolicy $policy): array
    {
        $incoming = [];
        $productId = ProductVariant::where('organization_id', $policy->organization_id)->whereKey($policy->product_variant_id)->value('product_id');
        $orders = PurchaseOrder::where('organization_id', $policy->organization_id)
            ->whereIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL])
            ->where(fn ($q) => $q->whereNull('approval_status')->orWhere('approval_status', '<>', 'rejected'))
            ->whereHas('items', fn ($q) => $q->where('product_variant_id', $policy->product_variant_id))
            ->select('id')->sharedLock()->lazyById(100);
        foreach ($orders as $order) {
            foreach (PurchaseOrderItem::where('purchase_order_id', $order->id)->where('product_variant_id', $policy->product_variant_id)->where('product_id', $productId)
                ->select(['id', 'receiving_location_id', 'quantity_ordered', 'quantity_received'])->sharedLock()->lazyById(500) as $line) {
                $key = (int) ($line->receiving_location_id ?? 0);
                $incoming[$key] = ($incoming[$key] ?? 0) + max(0, (int) $line->quantity_ordered - (int) $line->quantity_received);
            }
        }

        return $incoming;
    }

    private function assetsLocked(VariantReplenishmentPolicy $policy): array
    {
        $stub = ProductVariant::where('organization_id', $policy->organization_id)->findOrFail($policy->product_variant_id);
        $product = Product::where('organization_id', $policy->organization_id)->where('is_active', true)
            ->where('has_variants', true)->where('type', '<>', 'kit')->sharedLock()->findOrFail($stub->product_id);
        $variant = ProductVariant::where('organization_id', $policy->organization_id)->where('is_active', true)
            ->where('product_id', $product->id)->sharedLock()->findOrFail($policy->product_variant_id);
        $warehouse = Warehouse::where('organization_id', $policy->organization_id)->where('is_active', true)->sharedLock()->findOrFail($policy->warehouse_id);
        $supplier = Supplier::where('organization_id', $policy->organization_id)->where('is_active', true)->sharedLock()->findOrFail($policy->supplier_id);
        if (strtoupper($supplier->currency ?: Organization::currencyFor((int) $policy->organization_id)) !== $policy->currency) {
            throw ValidationException::withMessages(['currency' => 'Review this policy after the supplier currency change.']);
        }
        $locations = ProductLocation::where('organization_id', $policy->organization_id)->where('warehouse_id', $warehouse->id)
            ->active()->orderBy('id')->sharedLock()->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (! in_array((int) $policy->location_id, $locations, true)) {
            throw ValidationException::withMessages(['location_id' => 'The receiving location is no longer available in this warehouse.']);
        }

        return [$product, $variant, $warehouse, $supplier, $locations];
    }

    private function quantities(VariantReplenishmentPolicy $policy, int $onHand, int $incoming): array
    {
        $position = $onHand + $incoming;
        $quantity = $position > $policy->reorder_point ? 0
            : max((int) $policy->minimum_order_quantity, (int) $policy->target_stock - $position);
        if ($quantity > 0 && $policy->reorder_quantity) {
            $multiple = (int) $policy->reorder_quantity;
            $quantity = intdiv($quantity + $multiple - 1, $multiple) * $multiple;
        }
        if ($quantity < 0 || $quantity > 2147483647) {
            throw ValidationException::withMessages(['quantity' => 'The suggested order exceeds the supported quantity range.']);
        }

        return ['on_hand' => $onHand, 'incoming' => $incoming, 'position' => $position, 'suggested_quantity' => $quantity];
    }

    private function log(VariantReplenishmentPolicy $policy, User $actor, string $action, array $before, array $after): void
    {
        ActivityLog::create(['organization_id' => $policy->organization_id, 'user_id' => $actor->id,
            'subject_type' => $policy->getMorphClass(), 'subject_id' => $policy->id, 'action' => $action,
            'description' => 'Variant replenishment policy '.$policy->id, 'properties' => ['old' => $before, 'new' => $after]]);
    }
}
