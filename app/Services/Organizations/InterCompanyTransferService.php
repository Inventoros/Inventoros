<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Enums\Permission;
use App\Enums\TrackingType;
use App\Exceptions\InsufficientStockException;
use App\Models\Auth\Organization;
use App\Models\Inventory\InterCompanyTransfer;
use App\Models\Inventory\InterCompanyTransferBatch;
use App\Models\Inventory\InterCompanyTransferLine;
use App\Models\Inventory\InterCompanyTransferSerial;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\User;
use App\Services\StockReservationService;
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
 * Tracked units move with the totals: named serials leave the source (kept
 * there with status `transferred`) and become serials of the destination
 * product; named batch quantities leave their batches and land in a
 * destination batch with the same lot number and dates. A kit is moved as
 * its components, each booked as its own line.
 *
 * The actor must be a member of BOTH organizations holding `transfer_stock`
 * in each, and have warehouse access to every location named on each side.
 * Every product, variant, location, serial and batch is checked to belong to
 * the organization named for it. Each side's adjustments are booked inside
 * that organization (OrganizationContext), attributed to the actor working
 * there.
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

    private const MAX_SERIALS = 5000;

    private const MAX_UNIT_ENTRIES = 1000;

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
     * into; products only, variants carry no bins).
     *
     * Tracked products (both sides must be tracked the same way):
     * - serial: `serials`, the serial numbers of the source product's
     *   available units to move. `quantity` may be left out; when given it
     *   must equal the number of serials. A serial number that already exists
     *   in the destination organization fails the whole transfer, unless it
     *   is a serial that earlier left that organization, which is revived.
     * - batch: `batches`, a list of `{batch_id, quantity}` naming the source
     *   product's batches to draw on. `quantity` may be left out; when given
     *   it must equal the total. The destination gets the same lot number,
     *   manufacture and expiry dates; a destination lot with that number and
     *   another expiry date fails the whole transfer.
     *
     * Kits (both sides must be kits whose components have the same SKUs and
     * per-kit quantities): the line is booked as one line per component, for
     * quantity x per-kit quantity units (whole units only). A kit whose
     * components are tracked, sold by variant or kits themselves is refused:
     * transfer those components as their own lines.
     *
     * Retrying with the same $idempotencyKey returns the first transfer
     * instead of moving the stock again.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws AuthorizationException when the actor may not move stock out of or into either organization
     * @throws ValidationException for malformed lines, or records of the wrong organization
     * @throws InsufficientStockException when the source does not hold the quantity
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
     * @param  array<int, array<string, mixed>>  $lines  normalized lines
     */
    private function book(User $actor, User $source, User $destination, array $lines, ?string $notes, ?string $idempotencyKey): InterCompanyTransfer
    {
        $from = (int) $source->organization_id;
        $to = (int) $destination->organization_id;

        // A kit's components are only known once its bill of materials is
        // read, and they must be locked with everything else: read the bills
        // first, lock, then make sure they did not change in between.
        $lineProductIds = array_values(array_unique(array_merge(array_column($lines, 'from_product_id'), array_column($lines, 'to_product_id'))));
        $kitIds = Product::withoutGlobalScopes()->whereIn('id', $lineProductIds)->where('type', 'kit')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $boms = $this->billsOfMaterials($kitIds);
        $componentIds = array_merge([], ...array_map('array_keys', array_values($boms)));

        // Lock every product and variant on both sides in one ascending
        // order, so two transfers in opposite directions cannot deadlock.
        $products = Product::withoutGlobalScopes()
            ->whereIn('id', array_values(array_unique(array_merge($lineProductIds, $componentIds))))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $lockedKitIds = $products->filter(fn (Product $p) => $p->isKit() && in_array((int) $p->id, $lineProductIds, true))->keys()->map(fn ($id) => (int) $id)->all();
        if ($this->billsOfMaterials($lockedKitIds) !== $boms) {
            throw ValidationException::withMessages(['lines' => 'A kit changed while it was being transferred. Try again.']);
        }

        $lines = $this->explodeKits($lines, $from, $to, $products, $boms);

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

        foreach ($lines as $line) {
            $this->assertSide($line, 'from', $from, $products, $variants, $locations, $source);
            $this->assertSide($line, 'to', $to, $products, $variants, $locations, $destination);
            $this->assertTracking($line, $products[$line['from_product_id']], $products[$line['to_product_id']]);
        }

        $units = $this->resolveUnits($lines, $from, $to, $products);

        $names = Organization::query()->whereIn('id', [$from, $to])->pluck('name', 'id');

        $transfer = InterCompanyTransfer::query()->create([
            'reference' => 'ICT-'.strtoupper((string) Str::ulid()),
            'from_organization_id' => $from,
            'to_organization_id' => $to,
            'initiated_by' => $actor->getKey(),
            'idempotency_key' => $idempotencyKey,
            'notes' => $notes,
        ]);

        $createdLots = [];

        foreach ($lines as $position => $line) {
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

            $record = InterCompanyTransferLine::query()->create([
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
                'from_kit_product_id' => $line['from_kit_product_id'],
                'to_kit_product_id' => $line['to_kit_product_id'],
                'kit_quantity' => $line['kit_quantity'],
            ]);

            foreach ($units['serials'][$position] ?? [] as $k => [$sourceSerial, $revived]) {
                $this->moveSerial($record, $line, $k, $sourceSerial, $revived, $from, $to);
            }

            foreach ($units['batches'][$position] ?? [] as [$sourceBatch, $quantity, $destinationBatch]) {
                $this->moveBatch($record, $line, $sourceBatch, $quantity, $destinationBatch, $createdLots, $from, $to, $transfer, $names[$from]);
            }
        }

        $transfer->forceFill(['completed_at' => now()])->save();

        DB::afterCommit(fn () => do_action('inter_company_transfer_completed', $transfer, $actor));

        return $transfer->load(['lines.serials', 'lines.batches']);
    }

    private function adjust(Product $product, ?ProductVariant $variant, int $quantity, string $type, string $reason, ?string $notes, InterCompanyTransfer $transfer, User $actor, ?int $locationId): StockAdjustment
    {
        if ($variant !== null) {
            return StockAdjustment::adjustVariant($variant, $quantity, $type, $reason, $notes, $transfer, allowNegative: false, actor: $actor, locationId: $locationId);
        }

        return StockAdjustment::adjust($product, $quantity, $type, $reason, $notes, $transfer, allowNegative: false, actor: $actor, locationId: $locationId);
    }

    /**
     * The source serial stays behind with status `transferred`; the
     * destination gets a serial with the same number on its product (or the
     * one that earlier left it is revived).
     *
     * @param  array<string, mixed>  $line
     */
    private function moveSerial(InterCompanyTransferLine $record, array $line, int $k, ProductSerial $sourceSerial, ?ProductSerial $revived, int $from, int $to): void
    {
        $this->context->run($from, function () use ($sourceSerial): void {
            $sourceSerial->status = ProductSerial::STATUS_TRANSFERRED;
            $sourceSerial->save();
        });

        $destinationSerial = $this->context->run($to, function () use ($line, $k, $sourceSerial, $revived, $to): ProductSerial {
            if ($revived !== null) {
                // order_item_id is not mass assignable; a revived serial
                // carries no order of this organization's past.
                $revived->product_id = $line['to_product_id'];
                $revived->status = ProductSerial::STATUS_AVAILABLE;
                $revived->order_item_id = null;
                $revived->save();

                return $revived;
            }

            try {
                return ProductSerial::withoutGlobalScopes()->create([
                    'organization_id' => $to,
                    'product_id' => $line['to_product_id'],
                    'serial_number' => $sourceSerial->serial_number,
                    'status' => ProductSerial::STATUS_AVAILABLE,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A serial the lookup did not match (a concurrent insert, or
                // a collation that treats the numbers as equal).
                throw ValidationException::withMessages(["lines.{$line['index']}.serials.{$k}" => "Serial {$sourceSerial->serial_number} already exists in the destination organization."]);
            }
        });

        InterCompanyTransferSerial::query()->create([
            'inter_company_transfer_line_id' => $record->id,
            'serial_number' => $sourceSerial->serial_number,
            'from_serial_id' => $sourceSerial->id,
            'to_serial_id' => $destinationSerial->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, ProductBatch>  $createdLots  destination lots created by this transfer, by product, lot and expiry
     */
    private function moveBatch(InterCompanyTransferLine $record, array $line, ProductBatch $sourceBatch, int $quantity, ?ProductBatch $destinationBatch, array &$createdLots, int $from, int $to, InterCompanyTransfer $transfer, string $fromName): void
    {
        $this->context->run($from, fn () => $sourceBatch->decrement('quantity', $quantity));

        $key = $line['to_product_id'].'|'.$sourceBatch->batch_number.'|'.$sourceBatch->expiry_date?->toDateString();
        $destinationBatch ??= $createdLots[$key] ?? null;

        $destinationBatch = $this->context->run($to, function () use ($destinationBatch, $sourceBatch, $quantity, $line, $to, $transfer, $fromName): ProductBatch {
            if ($destinationBatch !== null) {
                $destinationBatch->increment('quantity', $quantity);

                return $destinationBatch;
            }

            return ProductBatch::withoutGlobalScopes()->create([
                'organization_id' => $to,
                'product_id' => $line['to_product_id'],
                'batch_number' => $sourceBatch->batch_number,
                'quantity' => $quantity,
                'manufactured_date' => $sourceBatch->manufactured_date,
                'expiry_date' => $sourceBatch->expiry_date,
                'notes' => "Received from {$fromName} by inter-company transfer {$transfer->reference}",
            ]);
        });

        $createdLots[$key] = $destinationBatch;

        InterCompanyTransferBatch::query()->create([
            'inter_company_transfer_line_id' => $record->id,
            'batch_number' => $sourceBatch->batch_number,
            'manufactured_date' => $sourceBatch->manufactured_date,
            'expiry_date' => $sourceBatch->expiry_date,
            'quantity' => $quantity,
            'from_batch_id' => $sourceBatch->id,
            'to_batch_id' => $destinationBatch->id,
        ]);
    }

    /**
     * Lock and check every serial and batch named, before anything is
     * written: serials and batches of both organizations in one ascending id
     * order each, after the products (the order the order fulfilment path
     * uses too).
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  Collection<int, Product>  $products
     * @return array{serials: array<int, array<int, array{0: ProductSerial, 1: ProductSerial|null}>>, batches: array<int, array<int, array{0: ProductBatch, 1: int, 2: ProductBatch|null}>>}
     */
    private function resolveUnits(array $lines, int $from, int $to, Collection $products): array
    {
        $resolved = ['serials' => [], 'batches' => []];
        $reservations = app(StockReservationService::class);

        $serialNumbers = array_merge([], ...array_map(fn (array $line) => $line['serials'] ?? [], $lines));

        if ($serialNumbers !== []) {
            $serials = ProductSerial::withoutGlobalScopes()
                ->whereIn('organization_id', [$from, $to])
                ->whereIn('serial_number', array_values(array_unique($serialNumbers)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $sourceSerials = $serials->filter(fn (ProductSerial $s) => (int) $s->organization_id === $from)->keyBy('serial_number');
            $destinationSerials = $serials->filter(fn (ProductSerial $s) => (int) $s->organization_id === $to)->keyBy('serial_number');

            foreach ($lines as $position => $line) {
                foreach ($line['serials'] ?? [] as $k => $number) {
                    $field = "lines.{$line['index']}.serials.{$k}";
                    $serial = $sourceSerials[$number] ?? null;

                    // Unknown, another organization's, another product's and
                    // unavailable serials are reported alike.
                    if ($serial === null || (int) $serial->product_id !== $line['from_product_id'] || $serial->status !== ProductSerial::STATUS_AVAILABLE) {
                        throw ValidationException::withMessages([$field => "Serial {$number} is not available on that product in the source organization."]);
                    }

                    // A serial held by a stock reservation stays in the
                    // source organization until its hold is consumed or
                    // released.
                    if ($reservations->isSerialHeld((int) $serial->id)) {
                        throw ValidationException::withMessages([$field => "Serial {$number} is reserved in the source organization."]);
                    }

                    $existing = $destinationSerials[$number] ?? null;

                    if ($existing !== null && $existing->status !== ProductSerial::STATUS_TRANSFERRED) {
                        throw ValidationException::withMessages([$field => "Serial {$number} already exists in the destination organization."]);
                    }

                    $resolved['serials'][$position][$k] = [$serial, $existing];
                }
            }
        }

        $batchIds = array_values(array_unique(array_merge([], ...array_map(fn (array $line) => array_column($line['batches'] ?? [], 'batch_id'), $lines))));

        if ($batchIds !== []) {
            // The lot numbers tell which destination batches to lock with them.
            $lotNumbers = ProductBatch::withoutGlobalScopes()->whereIn('id', $batchIds)->pluck('batch_number')->unique()->values()->all();
            $destinationProductIds = array_values(array_unique(array_column(array_filter($lines, fn (array $line) => $line['batches'] !== null), 'to_product_id')));

            $batches = ProductBatch::withoutGlobalScopes()
                ->where(fn ($query) => $query
                    ->whereIn('id', $batchIds)
                    ->orWhere(fn ($query) => $query
                        ->where('organization_id', $to)
                        ->whereIn('product_id', $destinationProductIds)
                        ->whereIn('batch_number', $lotNumbers)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $byId = $batches->keyBy('id');
            $demand = [];

            foreach ($lines as $position => $line) {
                foreach ($line['batches'] ?? [] as $k => ['batch_id' => $batchId, 'quantity' => $quantity]) {
                    $field = "lines.{$line['index']}.batches.{$k}.batch_id";
                    $batch = $byId[$batchId] ?? null;

                    if ($batch === null || (int) $batch->organization_id !== $from || (int) $batch->product_id !== $line['from_product_id']) {
                        throw ValidationException::withMessages([$field => 'The batch was not found on that product in the source organization.']);
                    }

                    $expiry = $batch->expiry_date?->toDateString();
                    $sameLot = $batches->filter(fn (ProductBatch $b) => (int) $b->organization_id === $to
                        && (int) $b->product_id === $line['to_product_id']
                        && $b->batch_number === $batch->batch_number);
                    $match = $sameLot->first(fn (ProductBatch $b) => $b->expiry_date?->toDateString() === $expiry);

                    if ($match === null && $sameLot->isNotEmpty()) {
                        throw ValidationException::withMessages([$field => "Lot {$batch->batch_number} already exists on the destination product with another expiry date."]);
                    }

                    $demand[$batchId] = ($demand[$batchId] ?? 0) + $quantity;
                    $resolved['batches'][$position][$k] = [$batch, $quantity, $match];
                }
            }

            foreach ($demand as $batchId => $quantity) {
                $batch = $byId[$batchId];
                $name = $products[(int) $batch->product_id]->name ?? 'the product';

                if ($quantity > (int) $batch->quantity) {
                    throw new InsufficientStockException("Batch {$batch->batch_number} of {$name} holds {$batch->quantity}, {$quantity} requested.");
                }

                // Units of the batch promised by stock reservations stay.
                $held = $reservations->reservedByBatch((int) $batch->product_id)[(int) $batch->id] ?? 0;

                if ($held > 0 && $quantity > (int) $batch->quantity - $held) {
                    throw new InsufficientStockException(
                        "Batch {$batch->batch_number} of {$name} has ".max(0, (int) $batch->quantity - $held)." available ({$batch->quantity} in the batch, {$held} reserved), {$quantity} requested."
                    );
                }
            }
        }

        return $resolved;
    }

    /**
     * Replace each kit line with one line per component, matched to the
     * destination kit's component with the same SKU.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  Collection<int, Product>  $products
     * @param  array<int, array<int, int>>  $boms  kit id => component id => hundredths per kit
     * @return array<int, array<string, mixed>>
     */
    private function explodeKits(array $lines, int $from, int $to, Collection $products, array $boms): array
    {
        $exploded = [];

        foreach ($lines as $line) {
            $fromProduct = $products[$line['from_product_id']] ?? null;
            $toProduct = $products[$line['to_product_id']] ?? null;
            $fromKit = $fromProduct?->isKit() ?? false;
            $toKit = $toProduct?->isKit() ?? false;

            if (! $fromKit && ! $toKit) {
                $exploded[] = $line;

                continue;
            }

            $index = $line['index'];
            $this->assertProduct($index, 'from', $fromProduct, $from);
            $this->assertProduct($index, 'to', $toProduct, $to);

            if (! $fromKit || ! $toKit) {
                throw ValidationException::withMessages(["lines.{$index}.to_product_id" => 'A kit can only be transferred into a kit with the same components.']);
            }

            foreach (['serials', 'batches'] as $units) {
                if ($line[$units] !== null) {
                    throw ValidationException::withMessages(["lines.{$index}.{$units}" => 'A kit moves as its components: name no serials or batches.']);
                }
            }

            if ($line['from_variant_id'] !== null || $line['to_variant_id'] !== null) {
                throw ValidationException::withMessages(["lines.{$index}.from_variant_id" => 'A kit has no variants.']);
            }

            $sourceBom = $this->bySku($boms[$fromProduct->id] ?? [], $products);
            $destinationBom = $this->bySku($boms[$toProduct->id] ?? [], $products);

            if ($sourceBom === []) {
                throw ValidationException::withMessages(["lines.{$index}.from_product_id" => 'This kit has no components to transfer.']);
            }

            if (array_map(fn (array $c) => $c[1], $sourceBom) !== array_map(fn (array $c) => $c[1], $destinationBom)) {
                throw ValidationException::withMessages(["lines.{$index}.to_product_id" => 'The destination kit must have the same components (by SKU) in the same quantities.']);
            }

            foreach ($sourceBom as $sku => [$componentId, $hundredths]) {
                $component = $products[$componentId];
                $destinationComponent = $products[$destinationBom[$sku][0]];

                foreach (['from' => $component, 'to' => $destinationComponent] as $side => $product) {
                    if ($this->trackingType($product) !== TrackingType::NONE || $product->isKit() || $product->has_variants) {
                        throw ValidationException::withMessages(["lines.{$index}.{$side}_product_id" => "Kit component {$sku} is tracked, sold by variant or a kit itself: transfer it as its own line."]);
                    }
                }

                $units = $hundredths * $line['quantity'];

                if ($units % 100 !== 0) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => "That many kits need a fraction of {$sku}: transfer a number of kits that needs whole units."]);
                }

                $exploded[] = array_merge($line, [
                    'from_product_id' => (int) $component->id,
                    'to_product_id' => (int) $destinationComponent->id,
                    'quantity' => intdiv($units, 100),
                    'from_kit_product_id' => (int) $fromProduct->id,
                    'to_kit_product_id' => (int) $toProduct->id,
                    'kit_quantity' => $line['quantity'],
                ]);
            }
        }

        return $exploded;
    }

    /**
     * @param  array<int, int>  $bom  component id => hundredths per kit
     * @param  Collection<int, Product>  $products
     * @return array<string, array{0: int, 1: int}> sku => [component id, hundredths per kit], by sku
     */
    private function bySku(array $bom, Collection $products): array
    {
        $bySku = [];

        foreach ($bom as $componentId => $hundredths) {
            $product = $products[$componentId] ?? null;

            if ($product === null) {
                continue;
            }

            $bySku[(string) $product->sku] = [$componentId, $hundredths];
        }

        ksort($bySku);

        return $bySku;
    }

    /**
     * Each kit's components, in hundredths of a unit per kit (component
     * quantities have two decimals), with components listed more than once
     * added together and zero quantities left out.
     *
     * @param  array<int, int>  $kitIds
     * @return array<int, array<int, int>>
     */
    private function billsOfMaterials(array $kitIds): array
    {
        if ($kitIds === []) {
            return [];
        }

        $boms = array_fill_keys($kitIds, []);

        foreach (ProductComponent::query()->whereIn('parent_product_id', $kitIds)->orderBy('id')->get() as $component) {
            $hundredths = (int) round((float) $component->quantity * 100);

            if ($hundredths <= 0) {
                continue;
            }

            $kitId = (int) $component->parent_product_id;
            $componentId = (int) $component->component_product_id;
            $boms[$kitId][$componentId] = ($boms[$kitId][$componentId] ?? 0) + $hundredths;
        }

        ksort($boms);

        foreach ($boms as &$bom) {
            ksort($bom);
        }

        return $boms;
    }

    /**
     * A record of another organization is reported exactly like a missing one.
     */
    private function assertProduct(int $index, string $side, ?Product $product, int $organizationId): void
    {
        if ($product === null || (int) $product->organization_id !== $organizationId || $product->trashed()) {
            throw ValidationException::withMessages(["lines.{$index}.{$side}_product_id" => 'The product was not found in that organization.']);
        }
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, ProductVariant>  $variants
     * @param  Collection<int, ProductLocation>  $locations
     */
    private function assertSide(array $line, string $side, int $organizationId, Collection $products, Collection $variants, Collection $locations, User $member): void
    {
        $index = $line['index'];
        $product = $products[$line["{$side}_product_id"]] ?? null;

        $this->assertProduct($index, $side, $product, $organizationId);

        if ($product->isKit()) {
            throw ValidationException::withMessages(["lines.{$index}.{$side}_product_id" => 'A kit holds no stock of its own; transfer its components.']);
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
     * Both sides tracked the same way, and the units named to match.
     *
     * @param  array<string, mixed>  $line
     */
    private function assertTracking(array $line, Product $fromProduct, Product $toProduct): void
    {
        $index = $line['index'];
        $type = $this->trackingType($fromProduct);

        if ($type !== $this->trackingType($toProduct)) {
            throw ValidationException::withMessages(["lines.{$index}.to_product_id" => 'The destination product must be tracked the same way (by serial, by batch or not at all).']);
        }

        $refuse = static fn (string $units, string $message) => throw ValidationException::withMessages(["lines.{$index}.{$units}" => $message]);

        match ($type) {
            TrackingType::SERIAL => $line['serials'] === null
                ? $refuse('serials', 'This product is serial tracked: name the serial numbers to transfer.')
                : ($line['batches'] !== null ? $refuse('batches', 'This product is serial tracked: name serials, not batches.') : null),
            TrackingType::BATCH => $line['batches'] === null
                ? $refuse('batches', 'This product is batch tracked: name the batches and quantities to transfer.')
                : ($line['serials'] !== null ? $refuse('serials', 'This product is batch tracked: name batches, not serials.') : null),
            default => $line['serials'] !== null
                ? $refuse('serials', 'This product is not serial tracked.')
                : ($line['batches'] !== null ? $refuse('batches', 'This product is not batch tracked.') : null),
        };

        if ($type !== TrackingType::NONE && ($line['from_variant_id'] !== null || $line['to_variant_id'] !== null)) {
            throw ValidationException::withMessages(["lines.{$index}.from_variant_id" => 'Tracked units are transferred by product, not by variant.']);
        }
    }

    private function trackingType(Product $product): TrackingType
    {
        $type = $product->tracking_type;

        if ($type instanceof TrackingType) {
            return $type;
        }

        return TrackingType::tryFrom((string) $type) ?? TrackingType::NONE;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLines(array $lines): array
    {
        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['lines' => 'Transfer between 1 and '.self::MAX_LINES.' lines.']);
        }

        $id = static fn (mixed $value): ?int => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $count = static fn (mixed $value): int|false => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $normalized = [];
        $seenSerials = [];

        foreach (array_values($lines) as $index => $line) {
            if (! is_array($line)) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Each line must be an array.']);
            }

            $serials = $this->normalizeSerials($index, $line['serials'] ?? null, $seenSerials);
            $batches = $this->normalizeBatches($index, $line['batches'] ?? null, $id, $count);

            $quantity = ($line['quantity'] ?? null) === null ? null : $count($line['quantity']);

            if ($quantity === false) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'The quantity must be a whole number of at least 1.']);
            }

            // Units named fix the quantity; a quantity given as well must agree.
            $units = $serials !== null ? count($serials) : ($batches !== null ? array_sum(array_column($batches, 'quantity')) : null);

            if ($units !== null && $quantity !== null && $quantity !== $units) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => "The quantity must equal the {$units} units named."]);
            }

            $normalized[] = [
                'index' => $index,
                'from_product_id' => $id($line['from_product_id'] ?? null) ?? throw ValidationException::withMessages(["lines.{$index}.from_product_id" => 'A product to take from is required.']),
                'to_product_id' => $id($line['to_product_id'] ?? null) ?? throw ValidationException::withMessages(["lines.{$index}.to_product_id" => 'A product to put into is required.']),
                'quantity' => $quantity ?? $units ?? throw ValidationException::withMessages(["lines.{$index}.quantity" => 'The quantity must be a whole number of at least 1.']),
                'from_variant_id' => $id($line['from_variant_id'] ?? null),
                'to_variant_id' => $id($line['to_variant_id'] ?? null),
                'from_location_id' => $id($line['from_location_id'] ?? null),
                'to_location_id' => $id($line['to_location_id'] ?? null),
                'serials' => $serials,
                'batches' => $batches,
                'from_kit_product_id' => null,
                'to_kit_product_id' => null,
                'kit_quantity' => null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, int>  $seen  serial number => line index, across the whole transfer
     * @return array<int, string>|null
     */
    private function normalizeSerials(int $index, mixed $serials, array &$seen): ?array
    {
        if ($serials === null) {
            return null;
        }

        if (! is_array($serials) || $serials === [] || ! array_is_list($serials)) {
            throw ValidationException::withMessages(["lines.{$index}.serials" => 'Serials must be a list of serial numbers.']);
        }

        $normalized = [];

        foreach ($serials as $k => $serial) {
            $number = is_string($serial) || is_int($serial) ? trim((string) $serial) : '';

            if ($number === '' || mb_strlen($number) > 255) {
                throw ValidationException::withMessages(["lines.{$index}.serials.{$k}" => 'Each serial number must be text of up to 255 characters.']);
            }

            if (isset($seen[$number])) {
                throw ValidationException::withMessages(["lines.{$index}.serials.{$k}" => "Serial {$number} is named twice."]);
            }

            if (count($seen) >= self::MAX_SERIALS) {
                throw ValidationException::withMessages(['lines' => 'Transfer at most '.self::MAX_SERIALS.' serials at once.']);
            }

            $seen[$number] = $index;
            $normalized[] = $number;
        }

        return $normalized;
    }

    /**
     * @param  callable(mixed): ?int  $id
     * @param  callable(mixed): (int|false)  $count
     * @return array<int, array{batch_id: int, quantity: int}>|null
     */
    private function normalizeBatches(int $index, mixed $batches, callable $id, callable $count): ?array
    {
        if ($batches === null) {
            return null;
        }

        if (! is_array($batches) || $batches === [] || ! array_is_list($batches) || count($batches) > self::MAX_UNIT_ENTRIES) {
            throw ValidationException::withMessages(["lines.{$index}.batches" => 'Batches must be a list of up to '.self::MAX_UNIT_ENTRIES.' batches with quantities.']);
        }

        $normalized = [];

        foreach ($batches as $k => $batch) {
            $batchId = is_array($batch) ? $id($batch['batch_id'] ?? null) : null;
            $quantity = is_array($batch) ? $count($batch['quantity'] ?? null) : false;

            if ($batchId === null) {
                throw ValidationException::withMessages(["lines.{$index}.batches.{$k}.batch_id" => 'A batch is required.']);
            }

            if ($quantity === false) {
                throw ValidationException::withMessages(["lines.{$index}.batches.{$k}.quantity" => 'The quantity must be a whole number of at least 1.']);
            }

            if (in_array($batchId, array_column($normalized, 'batch_id'), true)) {
                throw ValidationException::withMessages(["lines.{$index}.batches.{$k}.batch_id" => 'The batch is named twice in this line.']);
            }

            $normalized[] = ['batch_id' => $batchId, 'quantity' => $quantity];
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

        return $transfer?->load(['lines.serials', 'lines.batches']);
    }
}
