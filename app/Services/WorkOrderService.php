<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TrackingType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductBatch;
use App\Models\Inventory\ProductComponent;
use App\Models\Inventory\ProductSerial;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\WorkOrder;
use App\Models\Inventory\WorkOrderItem;
use App\Models\Inventory\WorkOrderOutput;
use App\Models\User;
use App\Support\SequenceNumberRetry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Work-order operations shared by the web WorkOrderController, the REST API
 * and the MCP server, so every surface creates, starts, completes, cancels
 * and deletes work orders through one path.
 *
 * Stock only moves when a work order completes: components (a product, a
 * variant, or the serials / batch quantities of a tracked product) are
 * consumed and the output (a product, a variant, new serials or a batch) is
 * received, all in one transaction. The serials and batches involved are
 * recorded in work_order_allocations / work_order_outputs.
 *
 * Lock order, everywhere: the work order row, then every product the order
 * touches in ascending id order, then every variant in ascending id order,
 * then tracking rows (serials, batches) per product. Product locks come first
 * because order creation, transfers and adjustments all lock the product
 * before its bins or tracking rows.
 */
final class WorkOrderService
{
    /**
     * Statuses in which a work order has never consumed or produced stock,
     * so deleting it leaves nothing to reverse.
     */
    public const DELETABLE_STATUSES = ['draft', 'cancelled'];

    /**
     * How deep kits inside kits are followed when a kit component is exploded.
     */
    private const MAX_KIT_DEPTH = 5;

    public function __construct(
        private readonly TrackedStockAllocationService $tracked,
        private readonly ProductLocationStockService $bins,
    ) {}

    /**
     * Validation rules for creating a work order, shared by the web and REST
     * requests. Ownership of the product, variants and warehouse is checked
     * by create() (and by the org-scoped exists rules below).
     *
     * @return array<string, mixed>
     */
    public static function creationRules(): array
    {
        return [
            'product_variant_id' => ['nullable', 'integer'],
            'component_variants' => ['nullable', 'array', 'max:500'],
            'component_variants.*.product_id' => ['required', 'integer'],
            'component_variants.*.product_variant_id' => ['required', 'integer'],
        ];
    }

    /**
     * Validation rules for completing a work order, shared by the web and
     * REST controllers.
     *
     * @return array<string, mixed>
     */
    public static function completionRules(int $maxQuantity): array
    {
        return [
            'quantity_produced' => ['nullable', 'integer', 'min:1', 'max:'.$maxQuantity],
            'allocations' => ['nullable', 'array', 'max:500'],
            'allocations.*.work_order_item_id' => ['required', 'integer'],
            'allocations.*.serial_ids' => ['nullable', 'array', 'max:10000'],
            'allocations.*.serial_ids.*' => ['integer'],
            'allocations.*.batches' => ['nullable', 'array', 'max:500'],
            'allocations.*.batches.*.product_batch_id' => ['required', 'integer'],
            'allocations.*.batches.*.quantity' => ['required', 'integer', 'min:1'],
            'output' => ['nullable', 'array'],
            'output.serial_numbers' => ['nullable', 'array', 'max:10000'],
            'output.serial_numbers.*' => ['required', 'string', 'max:255'],
            'output.batch_number' => ['nullable', 'string', 'max:255'],
            'output.expiry_date' => ['nullable', 'date'],
            'output.manufactured_date' => ['nullable', 'date'],
        ];
    }

    /**
     * Create a draft work order for an assembly, with one component line per
     * BOM component. A virtual kit in the BOM is exploded into its own
     * components (a kit holds no stock of its own). Components and outputs
     * sold by variant need the variant named.
     *
     * @param  array{product_id:int, quantity:int, warehouse_id?:int|null, notes?:string|null, product_variant_id?:int|null, component_variants?:list<array{product_id:int, product_variant_id:int}>}  $data
     *
     * @throws InvalidStateException
     */
    public function create(User $creator, array $data): WorkOrder
    {
        $organizationId = (int) $creator->organization_id;
        $quantity = (int) $data['quantity'];

        $product = Product::withoutGlobalScopes()
            ->whereKey($data['product_id'])
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        // Only assemblies are built. A kit is never an output: it has no
        // stock of its own to receive the units into.
        if ($product->type !== 'assembly') {
            throw new InvalidStateException('Work orders can only be created for assembly products.', 'invalid_product_type');
        }

        $componentLines = $this->componentLines($product, $quantity);
        $outputVariant = $this->resolveVariant($product, $this->intOrNull($data['product_variant_id'] ?? null));

        $chosenVariants = [];
        foreach ($data['component_variants'] ?? [] as $row) {
            $chosenVariants[(int) $row['product_id']] = (int) $row['product_variant_id'];
        }

        $lines = [];
        foreach ($componentLines as $line) {
            $variant = $this->resolveVariant($line['product'], $chosenVariants[$line['product']->id] ?? null);
            $lines[] = $line + ['variant' => $variant];
        }

        // Retry on a work_order_number unique collision (concurrent creates in
        // the same org read the same MAX+1); nothing serialises them otherwise.
        return SequenceNumberRetry::create(fn () => DB::transaction(function () use ($data, $organizationId, $creator, $quantity, $product, $outputVariant, $lines) {
            $workOrder = WorkOrder::create([
                'organization_id' => $organizationId,
                'product_id' => $product->id,
                'product_variant_id' => $outputVariant?->id,
                'created_by' => $creator->id,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'work_order_number' => WorkOrder::generateWorkOrderNumber($organizationId),
                'quantity' => $quantity,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                WorkOrderItem::create([
                    'work_order_id' => $workOrder->id,
                    'product_id' => $line['product']->id,
                    'product_variant_id' => $line['variant']?->id,
                    'kit_product_id' => $line['kit_id'],
                    'quantity_required' => round($line['quantity'], 2),
                    'quantity_consumed' => 0,
                ]);
            }

            return $workOrder;
        }));
    }

    /**
     * Start against the current status, serialized with completion,
     * cancellation and deletion. A stale request must not reopen finished work.
     *
     * Checks every component can be covered now: the variant's or product's
     * stock and, for a tracked product, its available serials or batch
     * quantity. Nothing is reserved; completion checks again under lock.
     *
     * @throws InvalidStateException|InsufficientStockException
     */
    public function start(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder): void {
            $locked = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['draft', 'pending'], true)) {
                throw new InvalidStateException('Only draft or pending work orders can be started.', 'invalid_status');
            }

            $locked->load(['product', 'variant', 'items.product', 'items.variant']);
            $this->assertOutputSupported($locked, $locked->product);

            $needed = [];
            foreach ($locked->items as $item) {
                $this->assertLineSupported($locked, $item, $item->product, $item->variant);
                $remaining = (float) $item->quantity_required - (float) $item->quantity_consumed;
                $key = $item->product_variant_id !== null ? "v{$item->product_variant_id}" : "p{$item->product_id}";
                $needed[$key] ??= ['item' => $item, 'quantity' => 0.0];
                $needed[$key]['quantity'] += $remaining;
            }

            foreach ($needed as ['item' => $item, 'quantity' => $quantity]) {
                $target = $item->variant ?? $item->product;
                $required = (int) ceil($quantity);

                if ($target->stock < $required) {
                    throw new InsufficientStockException("Insufficient stock for component '{$this->lineName($item)}'. Available: {$target->stock}, Required: {$required}.");
                }

                if ($item->variant === null) {
                    $tracked = $this->tracked->availableForProduction($item->product, $item);
                    if ($tracked !== null && $tracked < $required) {
                        throw new InsufficientStockException("Not enough tracked units for component '{$item->product->name}'. Available: {$tracked}, Required: {$required}.");
                    }
                }
            }

            $locked->update(['status' => 'in_progress', 'started_at' => now()]);
        });
    }

    /**
     * Complete an in-progress work order: consume the components in
     * proportion to what was produced and receive the output, all or nothing.
     *
     * @param  array{quantity_produced?:int|null, allocations?:list<array{work_order_item_id:int, serial_ids?:list<int>|null, batches?:list<array{product_batch_id:int, quantity:int}>|null}>|null, output?:array{serial_numbers?:list<string>|null, batch_number?:string|null, expiry_date?:string|null, manufactured_date?:string|null}|null}  $input
     * @return int the quantity produced
     *
     * @throws InvalidStateException|InsufficientStockException
     */
    public function complete(WorkOrder $workOrder, array $input = []): int
    {
        return DB::transaction(function () use ($workOrder, $input): int {
            // Lock and re-read so a concurrent or stale complete/cancel cannot
            // pass on an old status and move stock twice.
            $locked = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'in_progress') {
                throw new InvalidStateException('Only in-progress work orders can be completed.', 'invalid_status');
            }

            $produced = $this->intOrNull($input['quantity_produced'] ?? null) ?? $locked->quantity;
            if ($produced < 1 || $produced > $locked->quantity) {
                throw new InvalidStateException("Quantity produced must be between 1 and {$locked->quantity}.", 'invalid_quantity');
            }

            $locked->load('items');
            [$products, $variants] = $this->lockStock($locked);

            $output = $products->get($locked->product_id);
            $outputVariant = $locked->product_variant_id !== null ? $variants->get($locked->product_variant_id) : null;
            $this->assertOutputSupported($locked, $output, $outputVariant);

            $allocations = $this->allocationsByItem($locked, $input['allocations'] ?? null);

            // Consume in product, then variant order so tracking rows are
            // locked in the same order every time.
            $items = $locked->items->sortBy(fn (WorkOrderItem $item) => sprintf('%012d-%012d-%012d', $item->product_id, $item->product_variant_id ?? 0, $item->id));

            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                $variant = $item->product_variant_id !== null ? $variants->get($item->product_variant_id) : null;
                $this->assertLineSupported($locked, $item, $product, $variant);

                // Consume in proportion to what was produced, so a partial
                // completion does not burn components for units never made.
                $target = (int) ceil((float) $item->quantity_required * ($produced / $locked->quantity));
                $consume = $target - (int) $item->quantity_consumed;
                $choice = $allocations[$item->id] ?? null;

                if ($consume <= 0) {
                    continue;
                }

                $this->consumeLine($locked, $item, $product, $variant, $consume, $choice);
                $item->update(['quantity_consumed' => $target]);
            }

            $this->receiveOutput($locked, $output, $outputVariant, $produced, $input['output'] ?? []);

            $locked->update([
                'status' => 'completed',
                'quantity_produced' => $produced,
                'completed_at' => now(),
            ]);

            return $produced;
        });
    }

    /**
     * Cancel a draft, pending or in-progress work order. Stock only moves on
     * completion, so a work order cancelled through the app has nothing to
     * return; a line that does record consumption (older data) gets exactly
     * that back: its variant or product stock, its bin, and the serials and
     * batch quantities it consumed. A completed work order cannot be
     * cancelled; its stock movements are final.
     *
     * @throws InvalidStateException
     */
    public function cancel(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder): void {
            $locked = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['draft', 'pending', 'in_progress'], true)) {
                throw new InvalidStateException('Only draft, pending, or in-progress work orders can be cancelled.', 'invalid_status');
            }

            if ($locked->status === 'in_progress') {
                $locked->load('items');
                $consumed = $locked->items->filter(fn (WorkOrderItem $item) => (float) $item->quantity_consumed > 0);

                if ($consumed->isNotEmpty()) {
                    [$products, $variants] = $this->lockStock($locked, $consumed, withTrashed: true);

                    foreach ($consumed as $item) {
                        $this->reverseLine($locked, $item, $products->get($item->product_id), $item->product_variant_id !== null ? $variants->get($item->product_variant_id) : null);
                    }
                }
            }

            $locked->update(['status' => 'cancelled']);
        });
    }

    /**
     * Delete a draft or cancelled work order and its component lines.
     *
     * @throws InvalidStateException when the work order has started, completed or otherwise moved stock
     */
    public function delete(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder) {
            // Lock and re-check so a concurrent start cannot slip in between
            // the guard and the delete.
            $locked = WorkOrder::whereKey($workOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::DELETABLE_STATUSES, true)) {
                throw new InvalidStateException('Only draft or cancelled work orders can be deleted.', 'invalid_status');
            }

            $locked->items()->delete();
            $locked->delete();
        });
    }

    /**
     * The component lines building $multiplier units of an assembly would
     * consume, with virtual kits exploded into their own components.
     *
     * @return list<array{product: Product, quantity: float, kit_id: int|null}>
     *
     * @throws InvalidStateException when the assembly has no components or a kit cannot be exploded
     */
    public function componentLines(Product $assembly, float $multiplier = 1.0): array
    {
        $components = $assembly->components()->with('componentProduct')->orderBy('sort_order')->orderBy('id')->get();

        if ($components->isEmpty()) {
            throw new InvalidStateException('This assembly has no components defined. Add components before creating a work order.', 'no_components');
        }

        return $this->explode($components, $multiplier, null, [$assembly->id], 0);
    }

    /**
     * Expand BOM rows into component lines, following virtual kits into
     * their own components and multiplying quantities down the tree.
     *
     * @param  Collection<int, ProductComponent>  $components
     * @param  list<int>  $path  product ids above this level, to stop cycles
     * @return list<array{product: Product, quantity: float, kit_id: int|null}>
     */
    private function explode(Collection $components, float $multiplier, ?int $kitId, array $path, int $depth): array
    {
        $lines = [];

        foreach ($components as $component) {
            $product = $component->componentProduct;
            $quantity = (float) $component->quantity * $multiplier;

            if ($product === null) {
                throw new InvalidStateException('A component of this assembly is no longer available.', 'invalid_product');
            }

            if (! $product->isKit()) {
                $lines[] = ['product' => $product, 'quantity' => $quantity, 'kit_id' => $kitId];

                continue;
            }

            if (in_array($product->id, $path, true) || $depth >= self::MAX_KIT_DEPTH) {
                throw new InvalidStateException("Kit '{$product->name}' contains itself.", 'invalid_product');
            }

            $kitComponents = $product->components()->with('componentProduct')->orderBy('sort_order')->orderBy('id')->get();

            if ($kitComponents->isEmpty()) {
                throw new InvalidStateException("Kit '{$product->name}' has no components to consume.", 'unsupported_kit');
            }

            array_push($lines, ...$this->explode($kitComponents, $quantity, $kitId ?? $product->id, [...$path, $product->id], $depth + 1));
        }

        return $lines;
    }

    /**
     * The variant a line or output uses. A product sold by variant (variant
     * stock, not product stock) needs one; any variant named must belong to
     * the product.
     *
     * @throws InvalidStateException
     */
    private function resolveVariant(Product $product, ?int $variantId): ?ProductVariant
    {
        if ($variantId === null) {
            if ($this->soldByVariant($product)) {
                throw new InvalidStateException("'{$product->name}' is stocked by variant; choose the variant.", 'variant_required');
            }

            return null;
        }

        $variant = ProductVariant::whereKey($variantId)->where('product_id', $product->id)->first();

        if ($variant === null) {
            throw new InvalidStateException("That variant does not belong to '{$product->name}'.", 'invalid_variant');
        }

        return $variant;
    }

    private function soldByVariant(Product $product): bool
    {
        return (bool) $product->has_variants && $product->variants()->exists();
    }

    /**
     * Lock every product (ascending id) and then every variant (ascending id)
     * the work order's lines and output touch.
     *
     * @param  Collection<int, WorkOrderItem>|null  $items  defaults to every line
     * @return array{0: Collection<int, Product>, 1: Collection<int, ProductVariant>}
     */
    private function lockStock(WorkOrder $workOrder, ?Collection $items = null, bool $withTrashed = false): array
    {
        $items ??= $workOrder->items;

        $productIds = $items->pluck('product_id')->push($workOrder->product_id)->filter()->unique()->sort()->values();
        $products = Product::withoutGlobalScopes()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->whereIn('id', $productIds)
            ->where('organization_id', $workOrder->organization_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if (! $withTrashed && $products->count() !== $productIds->count()) {
            throw new InvalidStateException('A work order product is no longer available.', 'invalid_product');
        }

        $variantIds = $items->pluck('product_variant_id')->push($workOrder->product_variant_id)->filter()->unique()->sort()->values();
        $variants = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::query()
                ->when($withTrashed, fn ($query) => $query->withTrashed())
                ->whereIn('id', $variantIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        return [$products, $variants];
    }

    /**
     * A line can be consumed when its product still exists, is not a kit
     * (kits are exploded when the work order is created) and the line names
     * a variant of that product exactly when the product is stocked by
     * variant. Products can change after the work order was created.
     *
     * @throws InvalidStateException
     */
    private function assertLineSupported(WorkOrder $workOrder, WorkOrderItem $item, ?Product $product, ?ProductVariant $variant): void
    {
        if ($product === null || (int) $product->organization_id !== (int) $workOrder->organization_id) {
            throw new InvalidStateException('A work order product is no longer available.', 'invalid_product');
        }

        if ($product->isKit()) {
            throw new InvalidStateException("'{$product->name}' is now a kit, which holds no stock of its own. Create a new work order so its components are listed.", 'unsupported_kit');
        }

        $this->assertVariant($product, $item->product_variant_id, $variant);
    }

    /**
     * @throws InvalidStateException
     */
    private function assertOutputSupported(WorkOrder $workOrder, ?Product $product, ?ProductVariant $variant = null): void
    {
        if ($product === null || (int) $product->organization_id !== (int) $workOrder->organization_id) {
            throw new InvalidStateException('A work order product is no longer available.', 'invalid_product');
        }

        if ($product->isKit()) {
            throw new InvalidStateException("'{$product->name}' is a kit, which holds no stock of its own and cannot be produced.", 'unsupported_kit');
        }

        $this->assertVariant($product, $workOrder->product_variant_id, $variant ?? $workOrder->variant);
    }

    /**
     * @throws InvalidStateException
     */
    private function assertVariant(Product $product, ?int $variantId, ?ProductVariant $variant): void
    {
        if ($variantId === null) {
            if ($this->soldByVariant($product)) {
                throw new InvalidStateException("'{$product->name}' is now stocked by variant; create a new work order that names the variant.", 'variant_required');
            }

            return;
        }

        if ($variant === null || $variant->trashed() || (int) $variant->product_id !== (int) $product->id) {
            throw new InvalidStateException("The variant chosen for '{$product->name}' is no longer available.", 'invalid_variant');
        }
    }

    /**
     * Index the requested allocations by work order line, refusing any that
     * name a line of another work order or name one line twice.
     *
     * @param  list<array<string, mixed>>|null  $rows
     * @return array<int, array{serial_ids: list<int>|null, batches: list<array{product_batch_id:int, quantity:int}>|null}>
     *
     * @throws InvalidStateException
     */
    private function allocationsByItem(WorkOrder $workOrder, ?array $rows): array
    {
        $byItem = [];
        $itemIds = $workOrder->items->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($rows ?? [] as $row) {
            $itemId = (int) $row['work_order_item_id'];

            if (! in_array($itemId, $itemIds, true) || isset($byItem[$itemId])) {
                throw new InvalidStateException('An allocation names a component line that is not on this work order.', 'invalid_allocation');
            }

            $serials = $row['serial_ids'] ?? null;
            $batches = $row['batches'] ?? null;

            $byItem[$itemId] = [
                'serial_ids' => is_array($serials) && $serials !== [] ? array_values($serials) : null,
                'batches' => is_array($batches) && $batches !== [] ? array_values($batches) : null,
            ];
        }

        return $byItem;
    }

    /**
     * Take $quantity of a component out of stock: from the variant when the
     * line names one, otherwise from the product's tracked units (when it is
     * tracked), its bins and its total.
     *
     * @param  array{serial_ids: list<int>|null, batches: list<array{product_batch_id:int, quantity:int}>|null}|null  $choice
     *
     * @throws InvalidStateException|InsufficientStockException
     */
    private function consumeLine(WorkOrder $workOrder, WorkOrderItem $item, Product $product, ?ProductVariant $variant, int $quantity, ?array $choice): void
    {
        $type = $this->trackingType($product);
        $chosenSerials = $choice['serial_ids'] ?? null;
        $chosenBatches = $choice['batches'] ?? null;

        // Variant stock carries no serials or batches (the same rule order
        // fulfilment follows), so a choice only makes sense on a tracked,
        // non-variant line, and only of the kind the product tracks.
        if (($chosenSerials !== null && ($variant !== null || $type !== TrackingType::SERIAL))
            || ($chosenBatches !== null && ($variant !== null || $type !== TrackingType::BATCH))) {
            throw new InvalidStateException("'{$product->name}' does not take that kind of allocation.", 'invalid_allocation');
        }

        $reason = "Consumed for work order {$workOrder->work_order_number}";
        $notes = "Assembly of {$this->outputName($workOrder)}";

        if ($variant !== null) {
            StockAdjustment::adjustVariant($variant, -$quantity, 'assembly_consumption', $reason, $notes, $workOrder, allowNegative: false);

            return;
        }

        $this->tracked->consumeForProduction($product, $quantity, $item, $chosenSerials, $chosenBatches);

        // Draw the units out of the bins before the total drops (an unbinned
        // product seeds from its pre-consumption stock), then refuse to drive
        // the total negative.
        $this->bins->consume($product, $quantity);

        StockAdjustment::adjust($product, -$quantity, 'assembly_consumption', $reason, $notes, $workOrder, allowNegative: false, syncBins: false);
    }

    /**
     * Book the produced units: into the variant, or into the product's total
     * and primary bin plus new serials or a batch when the product is tracked.
     *
     * @param  array{serial_numbers?:list<string>|null, batch_number?:string|null, expiry_date?:string|null, manufactured_date?:string|null}|null  $input
     *
     * @throws InvalidStateException
     */
    private function receiveOutput(WorkOrder $workOrder, Product $product, ?ProductVariant $variant, int $quantity, ?array $input): void
    {
        $input ??= [];
        $type = $variant === null ? $this->trackingType($product) : null;
        $serialNumbers = array_values(array_filter(
            array_map(fn ($number) => trim((string) $number), $input['serial_numbers'] ?? []),
            fn (string $number) => $number !== '',
        ));
        $batchInput = array_filter([
            'batch_number' => trim((string) ($input['batch_number'] ?? '')),
            'expiry_date' => $input['expiry_date'] ?? null,
            'manufactured_date' => $input['manufactured_date'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (($serialNumbers !== [] && $type !== TrackingType::SERIAL) || ($batchInput !== [] && $type !== TrackingType::BATCH)) {
            throw new InvalidStateException("'{$product->name}' does not take that kind of output.", 'invalid_output');
        }

        $reason = "Produced from work order {$workOrder->work_order_number}";
        $notes = "Assembled {$quantity} units";
        $record = [
            'organization_id' => $workOrder->organization_id,
            'work_order_id' => $workOrder->id,
            'product_id' => $product->id,
        ];

        if ($variant !== null) {
            StockAdjustment::adjustVariant($variant, $quantity, 'assembly_production', $reason, $notes, $workOrder);
            WorkOrderOutput::create($record + ['product_variant_id' => $variant->id, 'quantity' => $quantity]);

            return;
        }

        StockAdjustment::adjust($product, $quantity, 'assembly_production', $reason, $notes, $workOrder, syncBins: false);
        $this->bins->receive($product, $quantity);

        match ($type) {
            TrackingType::SERIAL => $this->receiveSerials($product, $quantity, $serialNumbers, $record),
            TrackingType::BATCH => $this->receiveBatch($product, $quantity, $batchInput, $record),
            default => WorkOrderOutput::create($record + ['quantity' => $quantity]),
        };
    }

    /**
     * @param  list<string>  $numbers
     * @param  array<string, int>  $record
     *
     * @throws InvalidStateException
     */
    private function receiveSerials(Product $product, int $quantity, array $numbers, array $record): void
    {
        if (count($numbers) !== $quantity) {
            throw new InvalidStateException(
                "'{$product->name}' is serial-tracked: enter exactly {$quantity} serial numbers for the units produced; ".count($numbers).' were entered.',
                'serial_count_mismatch',
            );
        }

        if (count(array_unique(array_map('mb_strtolower', $numbers))) !== count($numbers)) {
            throw new InvalidStateException('The same serial number was entered twice.', 'invalid_serial');
        }

        $taken = ProductSerial::withoutGlobalScopes()
            ->where('organization_id', $product->organization_id)
            ->whereIn('serial_number', $numbers)
            ->value('serial_number');

        if ($taken !== null) {
            throw new InvalidStateException("Serial number {$taken} is already in use.", 'serial_taken');
        }

        foreach ($numbers as $number) {
            try {
                $serial = ProductSerial::create([
                    'organization_id' => $product->organization_id,
                    'product_id' => $product->id,
                    'serial_number' => $number,
                    'status' => ProductSerial::STATUS_AVAILABLE,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another request took the number since the check above.
                throw new InvalidStateException("Serial number {$number} is already in use.", 'serial_taken');
            }

            WorkOrderOutput::create($record + ['product_serial_id' => $serial->id, 'quantity' => 1]);
        }
    }

    /**
     * Receive into the named batch of this product (topping it up when it
     * exists) or a new one, generating a batch number when none is given.
     *
     * @param  array{batch_number?:string, expiry_date?:string, manufactured_date?:string}  $input
     * @param  array<string, int>  $record
     *
     * @throws InvalidStateException
     */
    private function receiveBatch(Product $product, int $quantity, array $input, array $record): void
    {
        $number = $input['batch_number'] ?? null;
        $expiry = isset($input['expiry_date']) ? date('Y-m-d', (int) strtotime($input['expiry_date'])) : null;

        $batch = $number === null ? null : ProductBatch::withoutGlobalScopes()
            ->where('organization_id', $product->organization_id)
            ->where('product_id', $product->id)
            ->where('batch_number', $number)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($batch !== null) {
            if ($expiry !== null && $batch->expiry_date !== null && $batch->expiry_date->toDateString() !== $expiry) {
                throw new InvalidStateException(
                    "Batch {$number} expires on {$batch->expiry_date->toDateString()}; use a new batch number for units expiring on {$expiry}.",
                    'batch_expiry_mismatch',
                );
            }

            $batch->increment('quantity', $quantity);

            if ($batch->expiry_date === null && $expiry !== null) {
                $batch->update(['expiry_date' => $expiry]);
            }
        } else {
            $batch = ProductBatch::create([
                'organization_id' => $product->organization_id,
                'product_id' => $product->id,
                'batch_number' => $number ?? ProductBatch::generateBatchNumber((int) $product->organization_id),
                'quantity' => $quantity,
                'expiry_date' => $expiry,
                'manufactured_date' => $input['manufactured_date'] ?? null,
            ]);
        }

        WorkOrderOutput::create($record + ['product_batch_id' => $batch->id, 'quantity' => $quantity]);
    }

    /**
     * Return what a line recorded as consumed: to its variant, or to the
     * product's total, primary bin and the exact serials / batches it took.
     */
    private function reverseLine(WorkOrder $workOrder, WorkOrderItem $item, ?Product $product, ?ProductVariant $variant): void
    {
        $quantity = (int) ceil((float) $item->quantity_consumed);
        $reason = "Reversed for cancelled work order {$workOrder->work_order_number}";
        $notes = 'Work order cancelled - restoring consumed stock';

        if ($variant !== null) {
            StockAdjustment::adjustVariant($variant, $quantity, 'assembly_reversal', $reason, $notes, $workOrder);
        } elseif ($product !== null) {
            StockAdjustment::adjust($product, $quantity, 'assembly_reversal', $reason, $notes, $workOrder, syncBins: false);
            $this->bins->receive($product, $quantity);
            $this->tracked->releaseForProduction($item);
        }

        $item->update(['quantity_consumed' => 0]);
    }

    private function trackingType(Product $product): TrackingType
    {
        $type = $product->tracking_type;

        if ($type instanceof TrackingType) {
            return $type;
        }

        return TrackingType::tryFrom((string) $type) ?? TrackingType::NONE;
    }

    private function lineName(WorkOrderItem $item): string
    {
        return $item->variant !== null
            ? "{$item->product->name} (".($item->variant->title ?? $item->variant->sku).')'
            : $item->product->name;
    }

    private function outputName(WorkOrder $workOrder): string
    {
        return (string) Product::withoutGlobalScopes()->withTrashed()->whereKey($workOrder->product_id)->value('name');
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
