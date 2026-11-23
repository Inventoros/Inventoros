<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\TrackingType;
use App\Exceptions\InvalidOrderItemException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Services\StockReservationService;
use Illuminate\Support\Collection;

/**
 * The rules for selling a kit on an order, and how many kits can be sold.
 *
 * A kit is sellable (v1) when it has components, every component quantity is
 * a whole number of at least 1, the kit has no variants, and each component
 * is untracked, not itself a kit or assembly, without variants and in the
 * same organization. Anything else is refused with a reason naming the kit.
 */
class KitSaleService
{
    public function __construct(private readonly StockReservationService $reservations) {}

    /**
     * Why the kit cannot be sold, or null when it can.
     */
    public function problem(Product $kit): ?string
    {
        return $this->inspect($kit, $this->rowsFor($kit));
    }

    /**
     * The kit's sellable components, with their products loaded.
     *
     * @return Collection<int, ProductComponent>
     *
     * @throws InvalidOrderItemException
     */
    public function components(Product $kit): Collection
    {
        $rows = $this->rowsFor($kit);
        $problem = $this->inspect($kit, $rows);

        if ($problem !== null) {
            throw new InvalidOrderItemException($problem);
        }

        return $rows;
    }

    /**
     * One stock draw per component; key matches OrderService's plain-line key.
     *
     * @return array<int, array{target: Product, key: string, per: int}>
     *
     * @throws InvalidOrderItemException
     */
    public function draws(Product $kit): array
    {
        return $this->components($kit)
            ->map(fn (ProductComponent $row) => [
                'target' => $row->componentProduct,
                'key' => 'p'.$row->component_product_id,
                'per' => (int) round((float) $row->quantity),
            ])
            ->values()
            ->all();
    }

    /**
     * How many whole kits can be sold now. 0 for an unsellable kit.
     */
    public function available(Product $kit): int
    {
        return $this->availableFor(collect([$kit]))[$kit->id] ?? 0;
    }

    /**
     * Availability for many kits in a constant number of queries.
     *
     * @param  Collection<int, Product>  $kits
     * @return array<int, int> kit id => kits available
     */
    public function availableFor(Collection $kits): array
    {
        $kits = $kits->unique('id');
        $rowsByKit = $this->loadRows($kits);

        $productIds = [];
        foreach ($rowsByKit as $rows) {
            foreach ($rows as $row) {
                $productIds[] = (int) $row->component_product_id;
            }
        }
        $reserved = $this->reservations->reservedTotals(array_values(array_unique($productIds)));

        $result = [];
        foreach ($kits as $kit) {
            $rows = $rowsByKit[$kit->id] ?? collect();
            if ($this->inspect($kit, $rows) !== null) {
                $result[$kit->id] = 0;

                continue;
            }

            $min = PHP_INT_MAX;
            foreach ($rows as $row) {
                $per = (int) round((float) $row->quantity);
                $free = (int) $row->componentProduct->stock - ($reserved[(int) $row->component_product_id] ?? 0);
                $min = min($min, intdiv(max(0, $free), $per));
            }
            $result[$kit->id] = $min === PHP_INT_MAX ? 0 : $min;
        }

        return $result;
    }

    /**
     * Unit cost of one kit: sum of component cost times quantity per kit.
     * Null when the kit is unsellable or any component has no cost.
     */
    public function costAtSale(Product $kit): ?string
    {
        $rows = $this->rowsFor($kit);
        if ($this->inspect($kit, $rows) !== null) {
            return null;
        }

        $cents = 0;
        foreach ($rows as $row) {
            $price = $row->componentProduct->purchase_price;
            if ($price === null) {
                return null;
            }
            $cents += (int) round(((float) $price) * 100) * (int) round((float) $row->quantity);
        }

        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * @return Collection<int, ProductComponent>
     */
    private function rowsFor(Product $kit): Collection
    {
        return $this->loadRows(collect([$kit]))[$kit->id] ?? collect();
    }

    /**
     * BOM rows (ordered) with their products, grouped by kit id.
     *
     * @param  Collection<int, Product>  $kits
     * @return array<int, Collection<int, ProductComponent>>
     */
    private function loadRows(Collection $kits): array
    {
        if ($kits->isEmpty()) {
            return [];
        }

        return ProductComponent::query()
            ->whereIn('parent_product_id', $kits->pluck('id')->all())
            ->with(['componentProduct' => fn ($q) => $q->withoutGlobalScopes()])
            ->ordered()
            ->orderBy('id')
            ->get()
            ->groupBy('parent_product_id')
            ->all();
    }

    /**
     * @param  Collection<int, ProductComponent>  $rows
     */
    private function inspect(Product $kit, Collection $rows): ?string
    {
        $name = $kit->name;

        if (! $kit->isKit()) {
            return "\"{$name}\" is not a kit.";
        }
        if ($kit->has_variants) {
            return "Kit \"{$name}\" has variants, which kits cannot be sold with yet.";
        }
        if ($rows->isEmpty()) {
            return "Kit \"{$name}\" has no components, so it cannot be sold.";
        }

        foreach ($rows as $row) {
            $component = $row->componentProduct;
            $qty = (float) $row->quantity;

            if ($component === null) {
                return "Kit \"{$name}\" has a component that no longer exists.";
            }
            $label = "component \"{$component->name}\"";

            if ($component->trashed()) {
                return "Kit \"{$name}\" contains {$label}, which has been deleted.";
            }

            if ($qty < 1 || $qty != floor($qty)) {
                return "Kit \"{$name}\" uses a fractional or zero quantity of {$label}; kits need whole numbers.";
            }
            if ((int) $component->organization_id !== (int) $kit->organization_id) {
                return "Kit \"{$name}\" has {$label} from another organization.";
            }
            if ($component->isKit() || $component->isAssembly()) {
                return "Kit \"{$name}\" contains {$label}, which is itself a kit or assembly.";
            }
            if ($component->has_variants) {
                return "Kit \"{$name}\" contains {$label}, which has variants.";
            }
            if ($component->tracking_type !== TrackingType::NONE) {
                return "Kit \"{$name}\" contains {$label}, which is serial or batch tracked.";
            }
        }

        return null;
    }
}
