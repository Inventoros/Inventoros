<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\TrackingType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Models\Inventory\OrderItemKitComponent;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductComponent;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\User;
use App\Services\OrderService;
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
                'per' => self::perKit($row),
            ])
            ->values()
            ->all();
    }

    /**
     * Take $kits kits' worth of components out of stock for a kit order line
     * and record what was taken in the line's snapshot rows.
     *
     * $draws are the kit's draws as the caller resolved them (draws()) and
     * checked against its running balances, with each target swapped for its
     * locked row. take() moves exactly those draws and never re-reads the
     * BOM, so a BOM edit committed after the caller's check cannot take a
     * component that was never checked, or lock one out of id order.
     *
     * Each draw goes through OrderService::fulfilLine: its bins are drawn
     * (the order's warehouse first), an `order_fulfillment` ledger row is
     * written against the order, and the take is refused rather than letting
     * stock go negative or into reserved units. The kit's own stock is never
     * touched. One OrderItemKitComponent row per draw records
     * quantity_per_kit and quantity_taken, which restocking reads instead of
     * the current BOM.
     *
     * Must run inside the caller's transaction, with every draw target's
     * product row already locked (with the order's other products, in id
     * order).
     *
     * @param  array<int, array{target: Product, key: string, per: int}>  $draws
     * @param  User|null  $actor  ledger attribution (see OrderService::fulfilLine)
     * @param  User|null  $viewer  warehouse access scope (null = unrestricted)
     *
     * @throws InsufficientStockException When a component is short.
     */
    public function take(Order $order, OrderItem $kitLine, int $kits, array $draws, string $reason, ?User $actor, ?User $viewer): void
    {
        if ($kits <= 0) {
            return;
        }

        foreach ($draws as $draw) {
            if (! ($draw['target'] ?? null) instanceof Product || ! is_int($draw['per'] ?? null) || $draw['per'] < 1) {
                throw new \InvalidArgumentException('Each kit draw needs a component Product target and a whole per-kit quantity of at least 1.');
            }
        }

        $orders = app(OrderService::class);
        foreach ($draws as $draw) {
            $component = $draw['target'];
            $per = $draw['per'];
            $taken = $kits * $per;

            $orders->fulfilLine($order, $component, null, $kitLine, $taken, $reason, $actor, $viewer);

            OrderItemKitComponent::create([
                'organization_id' => $order->organization_id,
                'order_item_id' => $kitLine->id,
                'component_product_id' => $component->id,
                'quantity_per_kit' => $per,
                'quantity_taken' => $taken,
            ]);
        }
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
                $per = self::perKit($row);
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
            $cents += (int) round(((float) $price) * 100) * self::perKit($row);
        }

        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Whole units of the component per kit. Only meaningful for a row that
     * passed inspect(), which refuses fractional and zero quantities.
     */
    private static function perKit(ProductComponent $row): int
    {
        return (int) round((float) $row->quantity);
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
