<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A read-only plan for an order that has not committed stock yet. */
final class OrderFulfilmentPlanner
{
    public function __construct(
        private readonly StockBalanceService $stock,
        private readonly StockReservationService $reservations,
        private readonly WarehouseAccessService $access,
    ) {}

    public static function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    public function plan(User $viewer, array $items): array
    {
        Validator::make(['items' => $items], self::rules())->validate();
        $organizationId = (int) $viewer->organization_id;
        $productIds = array_values(array_unique(array_map('intval', array_column($items, 'product_id'))));
        $products = Product::forOrganization($organizationId)->active()->whereIn('id', $productIds)->with('variants')->get()->keyBy('id');
        $this->reservations->preloadReserved($products);
        $targets = [];
        foreach ($items as $index => $item) {
            $product = $products->get((int) $item['product_id']);
            if ($product === null) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => 'Choose an active product in this organization.']);
            }
            $variantId = ! empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;
            $variant = $variantId !== null ? $product->variants->firstWhere('id', $variantId) : null;
            if (($variantId !== null && (! $product->has_variants || $variant === null || ! $variant->is_active)) || ($product->has_variants && $variant === null)) {
                throw ValidationException::withMessages(["items.{$index}.product_variant_id" => 'Choose an active variant belonging to this product.']);
            }
            $target = $variant ?? $product;
            $key = $this->key((int) $product->id, $variant?->id);
            $targets[$key] ??= [
                'product_id' => (int) $product->id, 'product_variant_id' => $variant?->id,
                'sku' => $variant?->sku ?: $product->sku, 'product_name' => $product->name, 'variant_title' => $variant?->title,
                'quantity' => 0, 'total_available' => max(0, (int) $target->stock - (int) $target->reserved_stock),
            ];
            $targets[$key]['quantity'] += (int) $item['quantity'];
        }

        $rows = $this->stock->query($viewer, ['product_ids' => $productIds, 'include_zero' => true])->reorder()->get()
            ->map(fn ($row) => $this->stock->normalise($row));
        $warehouses = Warehouse::forOrganization($organizationId)
            ->tap(fn ($q) => $this->access->scopeWarehouses($q, $viewer))
            ->orderByDesc('priority')->orderBy('name')->get(['id', 'name', 'priority', 'is_active']);
        $locations = ProductLocation::forOrganization($organizationId)->active()->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->orderBy('code')->get(['id', 'warehouse_id', 'name'])->keyBy('id');
        $byTarget = $rows->groupBy(fn ($row) => $this->key($row['product_id'], $row['product_variant_id']));
        $priorities = $warehouses->pluck('priority', 'id');
        $candidates = [];
        foreach ($warehouses->where('is_active', true) as $warehouse) {
            $lines = [];
            $missing = 0;
            $uncovered = 0;
            $transfers = [];
            foreach ($targets as $key => $target) {
                $bins = $byTarget->get($key, collect());
                $local = $bins->where('warehouse_id', $warehouse->id);
                $available = min($target['total_available'], (int) $local->sum(fn ($bin) => max(0, $bin['on_hand'] - $bin['reserved'])));
                $incoming = (int) $local->sum('in_transit');
                $shortfall = max(0, $target['quantity'] - $available);
                $missing += $shortfall;
                // Already-dispatched goods must not produce a second transfer suggestion.
                $needed = max(0, $shortfall - $incoming);
                $budget = max(0, $target['total_available'] - $available);
                $destinationId = $local->sortByDesc('on_hand')->first(fn ($bin) => $locations->has($bin['location_id']))['location_id']
                    ?? $locations->firstWhere('warehouse_id', $warehouse->id)?->id;
                $sources = $bins->filter(fn ($bin) => $bin['warehouse_id'] !== $warehouse->id && $bin['warehouse_id'] !== null && $locations->has($bin['location_id']))
                    ->sortBy(fn ($bin) => [-(int) ($priorities[$bin['warehouse_id']] ?? 0), -max(0, $bin['on_hand'] - $bin['reserved']), $bin['location_id']]);
                if ($destinationId !== null) {
                    foreach ($sources as $source) {
                        $quantity = min($needed, $budget, max(0, $source['on_hand'] - $source['reserved']));
                        if ($quantity <= 0) {
                            continue;
                        }
                        $transfers[] = [
                            'product_id' => $target['product_id'], 'product_variant_id' => $target['product_variant_id'], 'sku' => $target['sku'],
                            'from_location_id' => $source['location_id'], 'from_location_name' => $source['location_name'],
                            'from_warehouse_id' => $source['warehouse_id'], 'from_warehouse_name' => $source['warehouse_name'],
                            'to_location_id' => (int) $destinationId, 'to_warehouse_id' => (int) $warehouse->id, 'quantity' => $quantity,
                        ];
                        $needed -= $quantity;
                        $budget -= $quantity;
                    }
                }
                $uncovered += $needed;
                $line = $target;
                unset($line['total_available']);
                $lines[] = $line + ['available' => $available, 'in_transit' => $incoming, 'shortfall' => $shortfall, 'uncovered' => $needed];
            }
            $candidates[] = [
                'warehouse_id' => (int) $warehouse->id, 'warehouse_name' => $warehouse->name, 'priority' => (int) $warehouse->priority,
                'can_fulfil' => $missing === 0, 'missing_units' => $missing, 'uncovered_units' => $uncovered,
                'lines' => $lines, 'transfers' => $transfers,
            ];
        }
        usort($candidates, fn ($a, $b) => [$a['uncovered_units'], $a['missing_units'], -$a['priority'], $a['warehouse_name']]
            <=> [$b['uncovered_units'], $b['missing_units'], -$b['priority'], $b['warehouse_name']]);

        return [
            'as_of' => now()->toIso8601String(), 'recommended_warehouse_id' => $candidates[0]['warehouse_id'] ?? null,
            'total_candidates' => count($candidates), 'candidates' => array_slice($candidates, 0, 10),
        ];
    }

    private function key(int $productId, ?int $variantId): string
    {
        return $productId.':'.($variantId ?? 0);
    }
}
