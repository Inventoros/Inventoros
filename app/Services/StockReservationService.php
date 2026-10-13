<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockReservation;
use App\Models\Scopes\OrganizationScope;
use App\Services\Hooks\DomainHooks;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stock reservations: available to promise.
 *
 * A reservation (hold) keeps units that are on hand from being sold,
 * transferred out of their bin, allocated or written off by anyone but the
 * hold's owner. available = on hand - SUM(active holds), computed when needed
 * and never stored, so it cannot drift.
 *
 * Scopes (see the design doc, docs/plans/2026-10-05-stock-reservations-design.md):
 *  - a product hold (no location) constrains the product total only;
 *  - a bin hold (location_id) also constrains that bin;
 *  - a variant hold constrains the variant's stock;
 *  - a serial hold pins one serial (quantity 1), a batch hold part of one
 *    batch; both also count toward the product total.
 *
 * Every core path that takes stock out checks against available, under the
 * same product (or variant) row lock as its decrement. Physical corrections
 * (a recount, a damage or loss write-off) may take available below zero; core
 * then fires `reservation_shortfall` so the owner can react.
 *
 * The public methods are the plugin API. Plugins never write the table.
 */
final class StockReservationService
{
    /**
     * Adjustment types that record what physically happened (a count, a
     * write-off). They are never refused because stock is promised.
     */
    public const PHYSICAL_CORRECTION_TYPES = ['recount', 'count', 'damage', 'loss', 'correction'];

    public function __construct(private readonly DomainHooks $hooks) {}

    // ==================== PLUGIN API ====================

    /**
     * Hold $quantity units for $owner under $reference.
     *
     * Locks the product (and variant) row, checks that enough is available,
     * and writes the hold. A retried call with the same owner, reference and
     * target returns the existing hold; a hold that was released or expired is
     * reactivated; a consumed one cannot be reused.
     *
     * @param  string  $owner  the plugin slug, or "core"
     * @param  string  $reference  the owner's id for the hold (a booking, a wave)
     * @param  int|null  $locationId  hold units in one location bin
     * @param  ProductSerial|null  $serial  hold one specific serial (quantity must be 1)
     * @param  ProductBatch|null  $batch  hold units of one batch
     *
     * @throws InsufficientStockException when not enough is available
     * @throws InvalidStateException when the reference was already consumed, or holds a different quantity
     * @throws InvalidArgumentException on an invalid target
     *
     * @api
     */
    public function reserve(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        string $owner,
        string $reference,
        ?int $locationId = null,
        ?CarbonInterface $expiresAt = null,
        ?ProductSerial $serial = null,
        ?ProductBatch $batch = null,
    ): StockReservation {
        $owner = $this->validOwner($owner);
        $reference = $this->validReference($reference);

        if ($quantity <= 0) {
            throw new InvalidArgumentException('A reservation must hold at least one unit.');
        }

        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw new InvalidArgumentException('A reservation cannot expire in the past.');
        }

        if ($serial !== null && $quantity !== 1) {
            throw new InvalidArgumentException('A serial reservation holds exactly one unit.');
        }

        if ($serial !== null && $batch !== null) {
            throw new InvalidArgumentException('A reservation holds a serial or a batch, not both.');
        }

        if ($variant !== null && ($locationId !== null || $serial !== null || $batch !== null)) {
            throw new InvalidArgumentException('A variant reservation cannot name a location, serial or batch.');
        }

        if ($locationId !== null && ($serial !== null || $batch !== null)) {
            throw new InvalidArgumentException('Serials and batches have no location; reserve them without one.');
        }

        $reservation = DB::transaction(function () use ($product, $variant, $quantity, $owner, $reference, $locationId, $expiresAt, $serial, $batch) {
            // Products before variants before tracked records before holds:
            // the order every stock path locks in.
            $locked = Product::withoutGlobalScope(OrganizationScope::class)
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new InvalidArgumentException('The product to reserve does not exist.');
            }

            $organizationId = (int) $locked->organization_id;

            $lockedVariant = null;
            if ($variant !== null) {
                $lockedVariant = ProductVariant::withoutGlobalScope(OrganizationScope::class)
                    ->whereKey($variant->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedVariant === null || (int) $lockedVariant->product_id !== (int) $locked->id) {
                    throw new InvalidArgumentException("The variant does not belong to {$locked->name}.");
                }
            } elseif ($locked->has_variants) {
                throw new InvalidArgumentException("{$locked->name} is sold by variant; reserve one of its variants.");
            }

            if ($locationId !== null) {
                $locationExists = ProductLocation::withoutGlobalScope(OrganizationScope::class)
                    ->whereKey($locationId)
                    ->where('organization_id', $organizationId)
                    ->exists();

                if (! $locationExists) {
                    throw new InvalidArgumentException('The location to reserve in does not exist in this organization.');
                }
            }

            $scopeKey = StockReservation::scopeKeyFor((int) $locked->id, $lockedVariant?->id, $locationId, $serial?->id, $batch?->id);

            $existing = StockReservation::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('owner_type', $owner)
                ->where('owner_reference', $reference)
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->consumed_at !== null) {
                throw new InvalidStateException(
                    "Reservation {$owner}/{$reference} was already consumed; use a new reference.",
                    'reservation_consumed',
                );
            }

            if ($existing !== null && $existing->isActive()) {
                if ((int) $existing->quantity === $quantity) {
                    return $existing; // a retried call: hold once
                }

                throw new InvalidStateException(
                    "Reservation {$owner}/{$reference} already holds {$existing->quantity}; release it before reserving {$quantity}.",
                    'reservation_conflict',
                );
            }

            $label = $this->label($locked, $lockedVariant);
            $reservedBefore = $this->reservedTotal((int) $locked->id, $lockedVariant?->id);
            $onHand = (int) ($lockedVariant ?? $locked)->stock;

            if ($onHand - $reservedBefore < $quantity) {
                throw new InsufficientStockException(
                    "Cannot reserve {$quantity} of {$label}: only ".max(0, $onHand - $reservedBefore)
                    ." available ({$onHand} on hand, {$reservedBefore} reserved)."
                );
            }

            if ($locationId !== null) {
                $binOnHand = app(ProductLocationStockService::class)->onHandAt($locked, $locationId);
                $binReserved = $this->reservedAtLocation((int) $locked->id, $locationId);

                if ($binOnHand - $binReserved < $quantity) {
                    throw new InsufficientStockException(
                        "Cannot reserve {$quantity} of {$label} at that location: only ".max(0, $binOnHand - $binReserved)
                        ." available there ({$binOnHand} on hand, {$binReserved} reserved)."
                    );
                }
            }

            if ($serial !== null) {
                $this->assertSerialReservable($locked, $serial);
            }

            if ($batch !== null) {
                $this->assertBatchReservable($locked, $batch, $quantity);
            }

            $reservation = $existing ?? new StockReservation;
            $reservation->forceFill([
                'organization_id' => $organizationId,
                'product_id' => $locked->id,
                'product_variant_id' => $lockedVariant?->id,
                'location_id' => $locationId,
                'product_serial_id' => $serial?->id,
                'product_batch_id' => $batch?->id,
                'quantity' => $quantity,
                'owner_type' => $owner,
                'owner_reference' => $reference,
                'scope_key' => $scopeKey,
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'consumed_by_type' => null,
                'consumed_by_id' => null,
                'released_at' => null,
                'release_reason' => null,
            ])->save();

            $this->hooks->reservedChanged($reservation, (int) $locked->id, $lockedVariant?->id, $reservedBefore, $reservedBefore + $quantity);

            DB::afterCommit(fn () => do_action('stock_reserved', $reservation));

            return $reservation;
        });

        return $reservation;
    }

    /**
     * Release every active hold $owner has under $reference. Releasing only
     * frees stock, so it never fails for lack of it.
     *
     * @param  int|null  $organizationId  limit to one organization (needed outside a request, where no organization is active)
     * @return int the units released
     *
     * @api
     */
    public function release(string $owner, string $reference, ?int $organizationId = null): int
    {
        return $this->finish(
            fn (Builder $query) => $query->where('owner_type', $owner)->where('owner_reference', $reference),
            $organizationId,
            StockReservation::RELEASED,
        );
    }

    /**
     * Mark every active hold $owner has under $reference consumed: the units
     * left stock through the operation $by (an order, an adjustment) in the
     * same transaction, so they must not be counted twice. Call it inside that
     * transaction, before the operation takes the stock out. A second call
     * consumes nothing.
     *
     * Orders do this themselves when they carry the reservation
     * (`reservation_owner` and `reservation_reference`, see OrderService::create()).
     *
     * @return int the units consumed (0 when nothing was active)
     *
     * @api
     */
    public function consume(string $owner, string $reference, ?Model $by = null, ?int $organizationId = null): int
    {
        return DB::transaction(function () use ($owner, $reference, $by, $organizationId) {
            $rows = $this->lockActive(
                fn (Builder $query) => $query->where('owner_type', $owner)->where('owner_reference', $reference),
                $organizationId,
            );

            $this->markConsumed($rows);

            if ($by !== null) {
                $this->recordConsumer($rows, $by);
            } else {
                foreach ($rows as $row) {
                    DB::afterCommit(fn () => do_action('stock_reservation_consumed', $row, null));
                }
            }

            return (int) $rows->sum('quantity');
        });
    }

    /**
     * Units available to promise: on hand less active holds. With a variant,
     * the variant's; with a location, that bin's; otherwise the product
     * total (for a product sold by variant, its active variants' total less
     * their holds).
     *
     * @api
     */
    public function available(Product $product, ?ProductVariant $variant = null, ?int $locationId = null): int
    {
        return $this->onHand($product, $variant, $locationId) - $this->reserved($product, $variant, $locationId);
    }

    /**
     * Units held by active holds, in the same scopes as available().
     *
     * @api
     */
    public function reserved(Product $product, ?ProductVariant $variant = null, ?int $locationId = null): int
    {
        if ($variant !== null) {
            return $this->reservedTotal((int) $product->id, (int) $variant->id);
        }

        if ($locationId !== null) {
            return $this->reservedAtLocation((int) $product->id, $locationId);
        }

        if ($product->has_variants) {
            return (int) $this->activeQuery()
                ->where('product_id', $product->id)
                ->whereNotNull('product_variant_id')
                ->whereIn('product_variant_id', ProductVariant::withoutGlobalScopes()
                    ->select('id')
                    ->where('product_id', $product->id)
                    ->where('is_active', true))
                ->sum('quantity');
        }

        return $this->reservedTotal((int) $product->id, null);
    }

    /**
     * Every hold (in any state) $owner has under $reference.
     *
     * @return Collection<int, StockReservation>
     *
     * @api
     */
    public function holds(string $owner, string $reference, ?int $organizationId = null): Collection
    {
        return StockReservation::query()
            ->when($organizationId !== null, fn (Builder $q) => $q->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organizationId))
            ->where('owner_type', $owner)
            ->where('owner_reference', $reference)
            ->orderBy('id')
            ->get();
    }

    // ==================== ADMINISTRATION ====================

    /**
     * Release one hold (the admin list's release action).
     *
     * @return int the units released (0 when it was no longer active)
     */
    public function releaseHold(StockReservation $reservation): int
    {
        return $this->finish(
            fn (Builder $query) => $query->whereKey($reservation->getKey()),
            (int) $reservation->organization_id,
            StockReservation::ADMIN,
        );
    }

    /**
     * Release every active hold of an owner in every organization: a plugin
     * being uninstalled.
     *
     * @return int the units released
     */
    public function releaseOwner(string $owner, string $reason = StockReservation::UNINSTALLED): int
    {
        return $this->finish(fn (Builder $query) => $query->where('owner_type', $owner), null, $reason, everyOrganization: true);
    }

    /**
     * Release holds whose expiry has passed. They stopped counting when they
     * expired; this records it and tells the owner (stock_reservation_released).
     *
     * @return int the number of holds released
     */
    public function releaseExpired(): int
    {
        $released = 0;

        StockReservation::withoutGlobalScopes()
            ->whereNull('consumed_at')
            ->whereNull('released_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function (Collection $chunk) use (&$released): void {
                DB::transaction(function () use ($chunk, &$released): void {
                    $rows = StockReservation::withoutGlobalScopes()
                        ->whereIn('id', $chunk->modelKeys())
                        // Re-checked under the lock: consumed, released or
                        // extended since the chunk was read.
                        ->whereNull('consumed_at')
                        ->whereNull('released_at')
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now())
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    $this->markReleased($rows, StockReservation::EXPIRED);
                    $released += $rows->count();
                });
            });

        return $released;
    }

    // ==================== CORE CHECKS ====================

    /**
     * Units held against a product total (variant null: every product, bin,
     * serial and batch hold) or one variant.
     *
     * @param  array<int, int>  $exceptIds
     */
    public function reservedTotal(int $productId, ?int $variantId, array $exceptIds = []): int
    {
        return (int) $this->activeQuery()
            ->where('product_id', $productId)
            ->when(
                $variantId === null,
                fn (Builder $q) => $q->whereNull('product_variant_id'),
                fn (Builder $q) => $q->where('product_variant_id', $variantId),
            )
            ->when($exceptIds !== [], fn (Builder $q) => $q->whereNotIn('id', $exceptIds))
            ->sum('quantity');
    }

    /**
     * Units held in one location bin of a product.
     */
    public function reservedAtLocation(int $productId, int $locationId): int
    {
        return (int) $this->activeQuery()
            ->where('product_id', $productId)
            ->whereNull('product_variant_id')
            ->where('location_id', $locationId)
            ->sum('quantity');
    }

    /**
     * Units held per location bin of a product.
     *
     * @return array<int, int> location_id => units
     */
    public function reservedByLocation(int $productId): array
    {
        return $this->activeQuery()
            ->where('product_id', $productId)
            ->whereNull('product_variant_id')
            ->whereNotNull('location_id')
            ->selectRaw('location_id, SUM(quantity) as held')
            ->groupBy('location_id')
            ->pluck('held', 'location_id')
            ->map(fn ($held) => (int) $held)
            ->all();
    }

    /**
     * Serials of a product held by an active hold.
     *
     * @return array<int, int>
     */
    public function heldSerialIds(int $productId): array
    {
        return $this->activeQuery()
            ->where('product_id', $productId)
            ->whereNotNull('product_serial_id')
            ->pluck('product_serial_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Whether a serial is held by an active hold.
     */
    public function isSerialHeld(int $serialId): bool
    {
        return $this->activeQuery()->where('product_serial_id', $serialId)->exists();
    }

    /**
     * Units held per batch of a product.
     *
     * @return array<int, int> product_batch_id => units
     */
    public function reservedByBatch(int $productId): array
    {
        return $this->activeQuery()
            ->where('product_id', $productId)
            ->whereNotNull('product_batch_id')
            ->selectRaw('product_batch_id, SUM(quantity) as held')
            ->groupBy('product_batch_id')
            ->pluck('held', 'product_batch_id')
            ->map(fn ($held) => (int) $held)
            ->all();
    }

    /**
     * Whether an adjustment type records a physical correction, which is
     * never refused because stock is promised.
     */
    public function isPhysicalCorrection(string $type): bool
    {
        return in_array($type, self::PHYSICAL_CORRECTION_TYPES, true);
    }

    /**
     * Lock the active holds of a reservation an operation is about to
     * consume, and mark them consumed so the operation's own availability
     * checks no longer count them (available + that hold). Runs inside the
     * operation's transaction; rolls back with it.
     *
     * @return Collection<int, StockReservation>
     *
     * @throws InvalidStateException when the reservation has no active hold
     */
    public function claim(int $organizationId, string $owner, string $reference): Collection
    {
        $rows = $this->lockActive(
            fn (Builder $query) => $query->where('owner_type', $owner)->where('owner_reference', $reference),
            $organizationId,
        );

        if ($rows->isEmpty()) {
            throw new InvalidStateException(
                "Reservation {$owner}/{$reference} has no active hold to consume (it was consumed, released or has expired).",
                'reservation_not_active',
            );
        }

        $this->markConsumed($rows);

        return $rows;
    }

    /**
     * Record what consumed the claimed holds, and announce them after commit.
     *
     * @param  Collection<int, StockReservation>  $rows
     */
    public function recordConsumer(Collection $rows, Model $by): void
    {
        foreach ($rows as $row) {
            $row->forceFill([
                'consumed_by_type' => $by->getMorphClass(),
                'consumed_by_id' => $by->getKey(),
            ])->save();

            DB::afterCommit(fn () => do_action('stock_reservation_consumed', $row, $by));
        }
    }

    /**
     * Fire `reservation_shortfall` after commit when a physical correction
     * left fewer units on hand than are promised in a scope (the product
     * total, a variant, or one bin when $locationId is given).
     */
    public function reportShortfall(Product $product, ?ProductVariant $variant, ?int $locationId, int $onHand): void
    {
        $reserved = $locationId !== null
            ? $this->reservedAtLocation((int) $product->id, $locationId)
            : $this->reservedTotal((int) $product->id, $variant?->id);

        if ($reserved <= 0 || $onHand >= $reserved) {
            return;
        }

        $holds = $this->activeQuery()
            ->where('product_id', $product->id)
            ->when(
                $variant === null,
                fn (Builder $q) => $q->whereNull('product_variant_id'),
                fn (Builder $q) => $q->where('product_variant_id', $variant->id),
            )
            ->when($locationId !== null, fn (Builder $q) => $q->where('location_id', $locationId))
            ->orderBy('id')
            ->get();

        $shortfall = [
            'location_id' => $locationId,
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'short' => $reserved - $onHand,
            'reservations' => $holds,
        ];

        DB::afterCommit(fn () => do_action('reservation_shortfall', $product, $variant, $shortfall));
    }

    // ==================== INTERNALS ====================

    /**
     * Active holds, across organizations (callers filter by product, whose
     * ids are unique across organizations, or by organization).
     *
     * @return Builder<StockReservation>
     */
    private function activeQuery(): Builder
    {
        return StockReservation::withoutGlobalScopes()->active();
    }

    private function onHand(Product $product, ?ProductVariant $variant, ?int $locationId): int
    {
        if ($variant !== null) {
            return (int) ProductVariant::withoutGlobalScopes()->whereKey($variant->getKey())->value('stock');
        }

        if ($locationId !== null) {
            return app(ProductLocationStockService::class)->onHandAt($product, $locationId);
        }

        if ($product->has_variants) {
            return (int) ProductVariant::withoutGlobalScopes()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->sum('stock');
        }

        return (int) Product::withoutGlobalScopes()->whereKey($product->getKey())->value('stock');
    }

    /**
     * Release (or expire) the active holds $constrain selects, locking their
     * products first, in id order.
     *
     * @param  callable(Builder): Builder  $constrain
     */
    private function finish(callable $constrain, ?int $organizationId, string $reason, bool $everyOrganization = false): int
    {
        return DB::transaction(function () use ($constrain, $organizationId, $reason, $everyOrganization) {
            $rows = $this->lockActive($constrain, $organizationId, $everyOrganization);

            $this->markReleased($rows, $reason);

            return (int) $rows->sum('quantity');
        });
    }

    /**
     * Lock the products behind the selected active holds (id order), then the
     * holds themselves. Without an organization the active organization's
     * scope applies (none outside a request), unless $everyOrganization.
     *
     * @param  callable(Builder): Builder  $constrain
     * @return Collection<int, StockReservation>
     */
    private function lockActive(callable $constrain, ?int $organizationId, bool $everyOrganization = false): Collection
    {
        $base = function () use ($constrain, $organizationId, $everyOrganization): Builder {
            $query = match (true) {
                $organizationId !== null => StockReservation::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organizationId),
                $everyOrganization => StockReservation::withoutGlobalScope(OrganizationScope::class),
                default => StockReservation::query(),
            };

            return $constrain($query->active());
        };

        $productIds = $base()->distinct()->pluck('product_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($productIds === []) {
            return new Collection;
        }

        Product::withoutGlobalScopes()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get(['id']);

        return $base()->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * @param  Collection<int, StockReservation>  $rows
     */
    private function markConsumed(Collection $rows): void
    {
        $this->changeRows($rows, ['consumed_at' => now()]);
    }

    /**
     * @param  Collection<int, StockReservation>  $rows
     */
    private function markReleased(Collection $rows, string $reason): void
    {
        $this->changeRows($rows, ['released_at' => now(), 'release_reason' => $reason]);

        foreach ($rows as $row) {
            DB::afterCommit(fn () => do_action('stock_reservation_released', $row));
        }
    }

    /**
     * Apply $attributes to the holds and record the change of reserved stock
     * for stock_changed, per product or variant.
     *
     * @param  Collection<int, StockReservation>  $rows
     * @param  array<string, mixed>  $attributes
     */
    private function changeRows(Collection $rows, array $attributes): void
    {
        foreach ($rows->groupBy(fn (StockReservation $row) => $row->product_id.'|'.($row->product_variant_id ?? 0)) as $group) {
            /** @var StockReservation $first */
            $first = $group->first();

            foreach ($group as $row) {
                $row->forceFill($attributes)->save();
            }

            $variantId = $first->product_variant_id === null ? null : (int) $first->product_variant_id;
            $after = $this->reservedTotal((int) $first->product_id, $variantId);
            $this->hooks->reservedChanged($first, (int) $first->product_id, $variantId, $after + (int) $group->sum('quantity'), $after);
        }
    }

    private function assertSerialReservable(Product $product, ProductSerial $serial): void
    {
        $locked = ProductSerial::withoutGlobalScopes()->whereKey($serial->getKey())->lockForUpdate()->first();

        if ($locked === null || (int) $locked->product_id !== (int) $product->id) {
            throw new InvalidArgumentException("The serial does not belong to {$product->name}.");
        }

        if ($locked->status !== ProductSerial::STATUS_AVAILABLE) {
            throw new InsufficientStockException("Serial {$locked->serial_number} is not available (it is {$locked->status}).");
        }

        if ($this->isSerialHeld((int) $locked->id)) {
            throw new InsufficientStockException("Serial {$locked->serial_number} is already reserved.");
        }
    }

    private function assertBatchReservable(Product $product, ProductBatch $batch, int $quantity): void
    {
        $locked = ProductBatch::withoutGlobalScopes()->whereKey($batch->getKey())->lockForUpdate()->first();

        if ($locked === null || (int) $locked->product_id !== (int) $product->id) {
            throw new InvalidArgumentException("The batch does not belong to {$product->name}.");
        }

        $held = $this->reservedByBatch((int) $product->id)[(int) $locked->id] ?? 0;
        $free = (int) $locked->quantity - $held;

        if ($free < $quantity) {
            throw new InsufficientStockException(
                "Cannot reserve {$quantity} of batch {$locked->batch_number}: only ".max(0, $free)." available ({$locked->quantity} in the batch, {$held} reserved)."
            );
        }
    }

    private function validOwner(string $owner): string
    {
        $owner = trim($owner);

        if ($owner === '' || mb_strlen($owner) > 100) {
            throw new InvalidArgumentException('A reservation owner is the plugin slug (or "core"), at most 100 characters.');
        }

        return $owner;
    }

    private function validReference(string $reference): string
    {
        $reference = trim($reference);

        if ($reference === '' || mb_strlen($reference) > 191) {
            throw new InvalidArgumentException('A reservation reference is required, at most 191 characters.');
        }

        return $reference;
    }

    private function label(Product $product, ?ProductVariant $variant): string
    {
        if ($variant === null) {
            return $product->name;
        }

        return "{$product->name} (".($variant->title ?? $variant->sku ?? "variant {$variant->id}").')';
    }
}
