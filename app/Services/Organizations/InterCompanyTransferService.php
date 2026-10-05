<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\Permission;
use App\Enums\TrackingType;
use App\Models\Auth\Organization;
use App\Models\Inventory\InterCompanyTransfer;
use App\Models\Inventory\InterCompanyTransferLine;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\User;
use App\Services\WarehouseAccessService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Moves stock from one organization to another as one atomic, audited
 * operation: for every line an `inter_company_out` adjustment in the source
 * organization (never below zero) and an `inter_company_in` adjustment in
 * the destination, both referencing one InterCompanyTransfer, in one
 * transaction. Either every line moves on both sides or nothing does.
 *
 * The actor must be a member of BOTH organizations holding `transfer_stock`
 * in each, and have warehouse access to every location named on each side.
 * Every product, variant and location is checked to belong to the
 * organization named for it. Each side's adjustments are booked inside that
 * organization (OrganizationContext), attributed to the actor working there.
 *
 * Plugins build their documents (inter-company sales and purchases, in
 * transit, pricing) on top and call this for the stock movement.
 *
 * @api
 */
final class InterCompanyTransferService
{
    public const OUT = 'inter_company_out';

    public const IN = 'inter_company_in';

    private const MAX_LINES = 500;

    public function __construct(
        private readonly ActiveOrganization $organizations,
        private readonly OrganizationContext $context,
        private readonly WarehouseAccessService $warehouses,
    ) {}

    /**
     * Move stock from $fromOrganizationId to $toOrganizationId.
     *
     * Each line: `from_product_id`, `to_product_id`, `quantity` (a positive
     * integer), and optionally `from_variant_id` / `to_variant_id` (required
     * for a product sold by variant, refused otherwise) and
     * `from_location_id` / `to_location_id` (the bins to take from and put
     * into; products only, variants carry no bins). Serial- and batch-tracked
     * products and kits are refused: their units move with their own records.
     *
     * Retrying with the same $idempotencyKey returns the first transfer
     * instead of moving the stock again.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws AuthorizationException when the actor may not move stock out of or into either organization
     * @throws ValidationException for malformed lines, or records of the wrong organization
     * @throws \App\Exceptions\InsufficientStockException when the source does not hold the quantity
     *
     * @api
     */
    public function transfer(
        User $actor,
        int $fromOrganizationId,
        int $toOrganizationId,
        array $lines,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): InterCompanyTransfer {
        if ($fromOrganizationId === $toOrganizationId) {
            throw ValidationException::withMessages(['to_organization_id' => 'Choose another organization to transfer to.']);
        }

        $lines = $this->normalizeLines($lines);

        $source = $this->organizations->authorize($actor, $fromOrganizationId, Permission::TRANSFER_STOCK);
        $destination = $this->organizations->authorize($actor, $toOrganizationId, Permission::TRANSFER_STOCK);

        if ($idempotencyKey !== null && ($existing = $this->existing($fromOrganizationId, $toOrganizationId, $idempotencyKey)) !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->book($actor, $source, $destination, $lines, $notes, $idempotencyKey));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent retry with the same key won the race.
            if ($idempotencyKey !== null && ($existing = $this->existing($fromOrganizationId, $toOrganizationId, $idempotencyKey)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * @param  array<int, array{from_product_id: int, to_product_id: int, quantity: int, from_variant_id: int|null, to_variant_id: int|null, from_location_id: int|null, to_location_id: int|null}>  $lines
     */
    private function book(User $actor, User $source, User $destination, array $lines, ?string $notes, ?string $idempotencyKey): InterCompanyTransfer
    {
        $from = (int) $source->organization_id;
        $to = (int) $destination->organization_id;

        // Lock every product and variant on both sides in one ascending
        // order, so two transfers in opposite directions cannot deadlock.
        $products = Product::withoutGlobalScopes()
            ->whereIn('id', array_unique(array_merge(array_column($lines, 'from_product_id'), array_column($lines, 'to_product_id'))))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $variantIds = array_values(array_filter(array_merge(array_column($lines, 'from_variant_id'), array_column($lines, 'to_variant_id'))));
        $variants = $variantIds === [] ? new Collection : ProductVariant::withoutGlobalScopes()
            ->whereIn('id', array_unique($variantIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $locationIds = array_values(array_filter(array_merge(array_column($lines, 'from_location_id'), array_column($lines, 'to_location_id'))));
        $locations = $locationIds === [] ? new Collection : ProductLocation::withoutGlobalScopes()
            ->whereIn('id', array_unique($locationIds))
            ->get()
            ->keyBy('id');

        foreach ($lines as $index => $line) {
            $this->assertSide($index, 'from', $line, $from, $products, $variants, $locations, $source);
            $this->assertSide($index, 'to', $line, $to, $products, $variants, $locations, $destination);
        }

        $names = Organization::query()->whereIn('id', [$from, $to])->pluck('name', 'id');

        $transfer = InterCompanyTransfer::query()->create([
            'reference' => 'ICT-'.strtoupper((string) Str::ulid()),
            'from_organization_id' => $from,
            'to_organization_id' => $to,
            'initiated_by' => $actor->getKey(),
            'idempotency_key' => $idempotencyKey,
            'notes' => $notes,
        ]);

        foreach ($lines as $line) {
            $out = $this->context->run($from, fn () => $this->adjust(
                $products[$line['from_product_id']],
                $line['from_variant_id'] === null ? null : $variants[$line['from_variant_id']],
                -$line['quantity'],
                self::OUT,
                "Inter-company transfer {$transfer->reference} to {$names[$to]}",
                $notes,
                $transfer,
                $source,
                $line['from_location_id'],
            ));

            $in = $this->context->run($to, fn () => $this->adjust(
                $products[$line['to_product_id']],
                $line['to_variant_id'] === null ? null : $variants[$line['to_variant_id']],
                $line['quantity'],
                self::IN,
                "Inter-company transfer {$transfer->reference} from {$names[$from]}",
                $notes,
                $transfer,
                $destination,
                $line['to_location_id'],
            ));

            InterCompanyTransferLine::query()->create([
                'inter_company_transfer_id' => $transfer->id,
                'from_product_id' => $line['from_product_id'],
                'from_product_variant_id' => $line['from_variant_id'],
                'from_location_id' => $line['from_location_id'],
                'to_product_id' => $line['to_product_id'],
                'to_product_variant_id' => $line['to_variant_id'],
                'to_location_id' => $line['to_location_id'],
                'quantity' => $line['quantity'],
                'out_adjustment_id' => $out->id,
                'in_adjustment_id' => $in->id,
            ]);
        }

        $transfer->forceFill(['completed_at' => now()])->save();

        DB::afterCommit(fn () => do_action('inter_company_transfer_completed', $transfer, $actor));

        return $transfer->load('lines');
    }

    private function adjust(Product $product, ?ProductVariant $variant, int $quantity, string $type, string $reason, ?string $notes, InterCompanyTransfer $transfer, User $actor, ?int $locationId): StockAdjustment
    {
        if ($variant !== null) {
            return StockAdjustment::adjustVariant($variant, $quantity, $type, $reason, $notes, $transfer, allowNegative: false, actor: $actor);
        }

        return StockAdjustment::adjust($product, $quantity, $type, $reason, $notes, $transfer, allowNegative: false, actor: $actor, locationId: $locationId);
    }

    /**
     * @param  array{from_product_id: int, to_product_id: int, quantity: int, from_variant_id: int|null, to_variant_id: int|null, from_location_id: int|null, to_location_id: int|null}  $line
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, ProductVariant>  $variants
     * @param  Collection<int, ProductLocation>  $locations
     */
    private function assertSide(int $index, string $side, array $line, int $organizationId, Collection $products, Collection $variants, Collection $locations, User $member): void
    {
        $field = "lines.{$index}.{$side}_product_id";
        $product = $products[$line["{$side}_product_id"]] ?? null;

        // A record of another organization is reported exactly like a missing one.
        if ($product === null || (int) $product->organization_id !== $organizationId || $product->trashed()) {
            throw ValidationException::withMessages([$field => 'The product was not found in that organization.']);
        }

        if ($product->tracking_type !== null && $product->tracking_type !== TrackingType::NONE) {
            throw ValidationException::withMessages([$field => 'Serial and batch tracked products cannot be transferred between organizations this way.']);
        }

        if ($product->isKit()) {
            throw ValidationException::withMessages([$field => 'A kit holds no stock of its own; transfer its components.']);
        }

        $variantId = $line["{$side}_variant_id"];

        if ($variantId === null && $product->has_variants && $product->variants()->exists()) {
            throw ValidationException::withMessages(["lines.{$index}.{$side}_variant_id" => 'This product is sold by variant: name the variant.']);
        }

        if ($variantId !== null) {
            $variant = $variants[$variantId] ?? null;

            if ($variant === null || (int) $variant->product_id !== (int) $product->id || $variant->trashed()) {
                throw ValidationException::withMessages(["lines.{$index}.{$side}_variant_id" => 'The variant was not found on that product.']);
            }

            if ($line["{$side}_location_id"] !== null) {
                throw ValidationException::withMessages(["lines.{$index}.{$side}_location_id" => 'Variant stock is not held per location.']);
            }
        }

        $locationId = $line["{$side}_location_id"];

        // Without a location the units drain from (or land in) whichever bins
        // hold them, as for any adjustment: a user restricted to some
        // warehouses must name an accessible location (see
        // WarehouseAccessService::authorizeLocation(null)).
        if ($locationId === null && ! $this->warehouses->canAccessLocation($member, null)) {
            throw new AuthorizationException('You can only move stock you have warehouse access to: name a location.');
        }

        if ($locationId !== null) {
            $location = $locations[$locationId] ?? null;

            if ($location === null || (int) $location->organization_id !== $organizationId || $location->trashed()) {
                throw ValidationException::withMessages(["lines.{$index}.{$side}_location_id" => 'The location was not found in that organization.']);
            }

            if (! $this->warehouses->canAccessLocation($member, $location)) {
                throw new AuthorizationException('You do not have access to that location\'s warehouse.');
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{from_product_id: int, to_product_id: int, quantity: int, from_variant_id: int|null, to_variant_id: int|null, from_location_id: int|null, to_location_id: int|null}>
     */
    private function normalizeLines(array $lines): array
    {
        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['lines' => 'Transfer between 1 and '.self::MAX_LINES.' lines.']);
        }

        $id = static fn (mixed $value): ?int => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $normalized = [];

        foreach (array_values($lines) as $index => $line) {
            if (! is_array($line)) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Each line must be an array.']);
            }

            $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $normalized[] = [
                'from_product_id' => $id($line['from_product_id'] ?? null) ?? throw ValidationException::withMessages(["lines.{$index}.from_product_id" => 'A product to take from is required.']),
                'to_product_id' => $id($line['to_product_id'] ?? null) ?? throw ValidationException::withMessages(["lines.{$index}.to_product_id" => 'A product to put into is required.']),
                'quantity' => $quantity !== false ? $quantity : throw ValidationException::withMessages(["lines.{$index}.quantity" => 'The quantity must be a whole number of at least 1.']),
                'from_variant_id' => $id($line['from_variant_id'] ?? null),
                'to_variant_id' => $id($line['to_variant_id'] ?? null),
                'from_location_id' => $id($line['from_location_id'] ?? null),
                'to_location_id' => $id($line['to_location_id'] ?? null),
            ];
        }

        return $normalized;
    }

    private function existing(int $from, int $to, string $idempotencyKey): ?InterCompanyTransfer
    {
        $transfer = InterCompanyTransfer::query()
            ->where('from_organization_id', $from)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($transfer !== null && (int) $transfer->to_organization_id !== $to) {
            throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a transfer to another organization.']);
        }

        return $transfer?->load('lines');
    }
}
