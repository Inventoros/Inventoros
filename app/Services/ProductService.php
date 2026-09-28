<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductOption;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierPriceHistory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Persistence + media handling for products.
 *
 * Extracted from the Inventory\ProductController store/update god-methods
 * (P2-8): currency-map normalisation, base64 image validation/upload, and the
 * option/variant sync transaction all live here. The controller keeps request
 * validation, the plugin hooks, and the response; it hands a validated array to
 * create()/update() and gets back the persisted Product.
 */
final class ProductService
{
    /**
     * Why a product or variant edit may not set on-hand stock.
     */
    public const STOCK_EDIT_MESSAGE = 'On-hand stock cannot be set by editing the product. Record a stock adjustment instead (POST /api/v1/stock-adjustments, or the variant\'s adjust-stock endpoint), so the change is locked, audited and kept in step with location bins and approvals.';

    /**
     * Ledger type of the row that records a new product's or variant's
     * starting stock.
     */
    public const OPENING_STOCK_TYPE = 'opening_stock';

    /**
     * Create a product (plus its options/variants) from validated data.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        $data = $this->normaliseCurrencies($data);
        $data = $this->processStoreImages($data);

        $options = $data['options'] ?? [];
        $variants = $data['variants'] ?? [];
        $syncSuppliers = array_key_exists('suppliers', $data);
        $suppliers = $data['suppliers'] ?? [];
        unset($data['options'], $data['variants'], $data['suppliers']);

        return DB::transaction(function () use ($data, $options, $variants, $syncSuppliers, $suppliers) {
            $product = Product::create($data);
            $this->recordOpeningStock($product);

            if ($syncSuppliers) {
                $this->syncSuppliers($product, $suppliers);
            }

            if ($product->has_variants && ! empty($options)) {
                foreach ($options as $index => $optionData) {
                    ProductOption::create([
                        'product_id' => $product->id,
                        'name' => $optionData['name'],
                        'values' => $optionData['values'],
                        'position' => $index,
                    ]);
                }

                foreach ($variants as $index => $variantData) {
                    $this->recordOpeningVariantStock(ProductVariant::create($this->variantPayload($product, $variantData, $index)));
                }
            }

            return $product;
        });
    }

    /**
     * Update a product (plus its options/variants) from validated data.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        if (isset($data['images'])) {
            $data = $this->processUpdateImages($product, $data);
        }

        // Sync options and variants only when the caller actually sends that
        // specific key, and independently of each other: a partial update
        // carrying only `options` must not wipe the variants (and vice versa),
        // while the web edit form — which always submits both — keeps its
        // replace-on-save behaviour. An empty array for a present key is a
        // deliberate "clear these".
        $syncOptions = array_key_exists('options', $data);
        $syncVariants = array_key_exists('variants', $data);
        $disablingVariants = array_key_exists('has_variants', $data) && ! $data['has_variants'];
        $options = $data['options'] ?? [];
        $variants = $data['variants'] ?? [];
        // Supplier links follow the same rule: only a present `suppliers` key
        // re-syncs them (an empty array unlinks all); an API partial update
        // that omits it leaves the links alone.
        $syncSuppliers = array_key_exists('suppliers', $data);
        $suppliers = $data['suppliers'] ?? [];
        // On-hand stock is never written by an edit: it moves only through
        // the audited ledger (adjustments, orders, receipts, transfers). The
        // request layers reject or drop it; this is the backstop for every
        // surface, including plugin filters that add it back.
        unset($data['options'], $data['variants'], $data['suppliers'], $data['stock']);

        DB::transaction(function () use ($product, $data, $options, $variants, $syncOptions, $syncVariants, $disablingVariants, $syncSuppliers, $suppliers) {
            $product->update($data);

            if ($syncSuppliers) {
                $this->syncSuppliers($product, $suppliers);
            }

            if ($disablingVariants) {
                // Variants explicitly turned off: clear the options and retire
                // the variants (a variant with stock or history is kept,
                // deactivated, rather than deleted).
                $product->options()->delete();
                $this->retireVariants($product->variants()->pluck('id')->all());

                return;
            }

            if ($product->has_variants) {
                if ($syncOptions) {
                    $this->syncOptions($product, $options);
                }

                if ($syncVariants) {
                    $this->syncVariants($product, $variants);
                }
            }
        });

        return $product;
    }

    /**
     * Record a newly created product's starting stock as a ledger row
     * (0 -> stock) and seed its primary location bin with it, so the opening
     * quantity is audited and binned like every other movement. No-op for a
     * product created with no stock.
     *
     * Call right after creating the product, inside the same transaction.
     * The row is attributed to $actor, falling back to the signed-in user;
     * with neither (a console seeder) only the bin is seeded.
     */
    public function recordOpeningStock(Product $product, ?User $actor = null): void
    {
        $quantity = (int) $product->stock;
        if ($quantity <= 0) {
            return;
        }

        $userId = $actor?->id ?? auth()->id();
        if ($userId !== null) {
            StockAdjustment::create([
                'organization_id' => $product->organization_id,
                'product_id' => $product->id,
                'location_id' => $product->location_id,
                'user_id' => $userId,
                'type' => self::OPENING_STOCK_TYPE,
                'quantity_before' => 0,
                'quantity_after' => $quantity,
                'adjustment_quantity' => $quantity,
                'reason' => 'Opening stock',
            ]);
        }

        app(ProductLocationStockService::class)->ensureBinned($product);
    }

    /**
     * Record a newly created variant's starting stock as a ledger row
     * (0 -> stock). Variant stock has no location bins. Same attribution
     * rules as recordOpeningStock().
     */
    public function recordOpeningVariantStock(ProductVariant $variant, ?User $actor = null): void
    {
        $quantity = (int) $variant->stock;
        $userId = $actor?->id ?? auth()->id();
        if ($quantity <= 0 || $userId === null) {
            return;
        }

        StockAdjustment::create([
            'organization_id' => $variant->organization_id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'user_id' => $userId,
            'type' => self::OPENING_STOCK_TYPE,
            'quantity_before' => 0,
            'quantity_after' => $quantity,
            'adjustment_quantity' => $quantity,
            'reason' => 'Opening stock',
        ]);
    }

    /**
     * Replace a product's supplier links with the given rows.
     *
     * Each row: supplier_id, supplier_sku?, cost_price?, lead_time_days?,
     * minimum_order_quantity?, is_primary?. Exactly one resulting link is
     * primary: the first row flagged is_primary, or the first row when none
     * is. Every supplier must belong to the product's organization (callers
     * validate this too; this is the backstop for surfaces that don't). A cost
     * that is new or differs from the stored one is written to the supplier
     * price history.
     *
     * @param  array<int, array<string, mixed>>  $rows
     *
     * @throws InvalidArgumentException when a supplier is not in the product's organization
     */
    public function syncSuppliers(Product $product, array $rows): void
    {
        $rows = array_values(array_filter($rows, fn ($row) => is_array($row) && ! empty($row['supplier_id'])));
        $ids = array_map(fn ($row) => (int) $row['supplier_id'], $rows);

        $ownIds = Supplier::withoutGlobalScopes()
            ->where('organization_id', $product->organization_id)
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count(array_diff($ids, $ownIds)) > 0) {
            throw new InvalidArgumentException('One or more suppliers do not belong to this organization.');
        }

        $primaryIndex = 0;
        foreach ($rows as $index => $row) {
            if (filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $primaryIndex = $index;
                break;
            }
        }

        $existing = DB::table('product_supplier')
            ->where('product_id', $product->id)
            ->pluck('cost_price', 'supplier_id');

        $blankToNull = fn ($value) => ($value === null || $value === '') ? null : $value;

        $sync = [];
        foreach ($rows as $index => $row) {
            $supplierId = (int) $row['supplier_id'];
            $cost = $blankToNull($row['cost_price'] ?? null);
            $cost = $cost === null ? null : round((float) $cost, 2);
            $leadTime = $blankToNull($row['lead_time_days'] ?? null);
            $moq = $blankToNull($row['minimum_order_quantity'] ?? null);

            $sync[$supplierId] = [
                'supplier_sku' => $blankToNull($row['supplier_sku'] ?? null),
                'cost_price' => $cost,
                'lead_time_days' => $leadTime === null ? null : (int) $leadTime,
                'minimum_order_quantity' => $moq === null ? null : (int) $moq,
                'is_primary' => $index === $primaryIndex,
            ];

            $previous = $existing->get($supplierId);
            if ($cost !== null && ($previous === null || round((float) $previous, 2) !== $cost)) {
                SupplierPriceHistory::record($product, $supplierId, $cost, SupplierPriceHistory::SOURCE_SUPPLIER_LINK);
            }
        }

        $product->suppliers()->sync($sync);
        $product->unsetRelation('suppliers');
    }

    /**
     * Make one supplier the product's primary supplier, creating or updating
     * that link while keeping the product's other supplier links (demoted).
     * Null/blank attributes leave the stored value unchanged. Used by the
     * product import, which only knows about the primary supplier.
     *
     * @param  array{supplier_sku?: string|null, cost_price?: float|int|string|null, lead_time_days?: int|null}  $attributes
     */
    public function setPrimarySupplier(Product $product, int $supplierId, array $attributes = []): void
    {
        $rows = DB::table('product_supplier')
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($link) => [
                'supplier_id' => (int) $link->supplier_id,
                'supplier_sku' => $link->supplier_sku,
                'cost_price' => $link->cost_price,
                'lead_time_days' => $link->lead_time_days,
                'minimum_order_quantity' => $link->minimum_order_quantity,
                'is_primary' => false,
            ])
            ->keyBy('supplier_id')
            ->all();

        $row = $rows[$supplierId] ?? ['supplier_id' => $supplierId];
        foreach (['supplier_sku', 'cost_price', 'lead_time_days'] as $key) {
            if (isset($attributes[$key]) && $attributes[$key] !== '') {
                $row[$key] = $attributes[$key];
            }
        }
        $row['is_primary'] = true;

        unset($rows[$supplierId]);

        $this->syncSuppliers($product, array_merge([$row], array_values($rows)));
    }

    /**
     * Convert the price_in_currencies list-of-pairs into a {currency: price} map.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normaliseCurrencies(array $data): array
    {
        if (! empty($data['price_in_currencies'])) {
            $currencies = [];
            foreach ($data['price_in_currencies'] as $currencyPrice) {
                $currencies[$currencyPrice['currency']] = $currencyPrice['price'];
            }
            $data['price_in_currencies'] = $currencies;
        }

        return $data;
    }

    /**
     * Validate + store base64 images on create, setting the thumbnail.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function processStoreImages(array $data): array
    {
        if (empty($data['images'])) {
            return $data;
        }

        $imagePaths = [];
        foreach ($data['images'] as $index => $imageData) {
            if (isset($imageData['preview']) && str_starts_with($imageData['preview'], 'data:image/')) {
                $this->validateBase64Image($imageData['preview']);

                $base64 = substr($imageData['preview'], strpos($imageData['preview'], ',') + 1);
                $imageContent = base64_decode($base64);

                $extension = $this->getImageExtensionFromBase64($imageData['preview']);
                $filename = 'products/'.uniqid().'_'.time().'.'.$extension;

                Storage::disk('public')->put($filename, $imageContent);
                $imagePaths[] = $filename;

                if ($index === 0) {
                    $data['thumbnail'] = $filename;
                }
            }
        }
        $data['images'] = $imagePaths;

        return $data;
    }

    /**
     * Reconcile images on update: keep existing URLs, upload new base64,
     * delete removed files, set the thumbnail.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function processUpdateImages(Product $product, array $data): array
    {
        $imagePaths = [];
        $oldImages = $product->images ?? [];

        foreach ($data['images'] as $index => $imageData) {
            // Existing image kept by URL
            if (isset($imageData['url']) && ! str_starts_with($imageData['preview'], 'data:image/')) {
                $path = str_replace('/storage/', '', $imageData['url']);
                $imagePaths[] = $path;
            }
            // New base64 image uploaded
            elseif (isset($imageData['preview']) && str_starts_with($imageData['preview'], 'data:image/')) {
                $this->validateBase64Image($imageData['preview']);

                $base64 = substr($imageData['preview'], strpos($imageData['preview'], ',') + 1);
                $imageContent = base64_decode($base64);

                $extension = $this->getImageExtensionFromBase64($imageData['preview']);
                $filename = 'products/'.uniqid().'_'.time().'.'.$extension;

                Storage::disk('public')->put($filename, $imageContent);
                $imagePaths[] = $filename;
            }

            if ($index === 0 && ! empty($imagePaths)) {
                $data['thumbnail'] = end($imagePaths);
            }
        }

        // Delete removed images
        foreach ($oldImages as $oldImage) {
            if (! in_array($oldImage, $imagePaths)) {
                Storage::disk('public')->delete($oldImage);
            }
        }

        $data['images'] = $imagePaths;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $variantData
     * @return array<string, mixed>
     */
    private function variantPayload(Product $product, array $variantData, int $index): array
    {
        return [
            'product_id' => $product->id,
            'organization_id' => $product->organization_id,
            'sku' => $variantData['sku'] ?? null,
            'barcode' => $variantData['barcode'] ?? null,
            'title' => $variantData['title'] ?? implode(' / ', array_values($variantData['option_values'])),
            'option_values' => $variantData['option_values'],
            'price' => $variantData['price'] ?? null,
            'purchase_price' => $variantData['purchase_price'] ?? null,
            'stock' => $variantData['stock'] ?? 0,
            'min_stock' => $variantData['min_stock'] ?? 0,
            'is_active' => $variantData['is_active'] ?? true,
            'position' => $index,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    private function syncOptions(Product $product, array $options): void
    {
        $existingOptionIds = $product->options()->pluck('id')->toArray();
        $incomingOptionIds = [];

        foreach ($options as $index => $optionData) {
            if (! empty($optionData['id'])) {
                $option = ProductOption::find($optionData['id']);
                if ($option && $option->product_id === $product->id) {
                    $option->update([
                        'name' => $optionData['name'],
                        'values' => $optionData['values'],
                        'position' => $index,
                    ]);
                    $incomingOptionIds[] = $optionData['id'];
                }
            } else {
                $option = ProductOption::create([
                    'product_id' => $product->id,
                    'name' => $optionData['name'],
                    'values' => $optionData['values'],
                    'position' => $index,
                ]);
                $incomingOptionIds[] = $option->id;
            }
        }

        $optionsToDelete = array_diff($existingOptionIds, $incomingOptionIds);
        if (! empty($optionsToDelete)) {
            ProductOption::whereIn('id', $optionsToDelete)->delete();
        }
    }

    /**
     * Sync a product's variants to the incoming rows without churning ids.
     *
     * Order, purchase order and return lines reference variants by id and a
     * variant's stock lives on its row, so an existing variant must be
     * updated in place, never recreated. Each incoming row resolves to an
     * existing variant by, in order: its `id`; its SKU; its exact option
     * combination. Only a row that matches nothing creates a variant (with an
     * opening-stock ledger row). Stock is never written to an existing
     * variant here: it moves only through the ledger.
     *
     * A row with an `id` replaces the variant's fields (the web form sends
     * them all); a row matched by SKU or options only overwrites the fields
     * it carries, so a partial API row cannot blank the SKU or price.
     *
     * Existing variants absent from the payload are retired: deactivated when
     * they hold stock or are referenced, soft-deleted otherwise.
     *
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        /** @var Collection<int, ProductVariant> $unclaimed */
        $unclaimed = $product->variants()->get()->keyBy('id');
        $existingVariantIds = $unclaimed->keys()->all();
        $incomingVariantIds = [];

        foreach ($variants as $index => $variantData) {
            $variantPayload = $this->variantPayload($product, $variantData, $index);

            if (! empty($variantData['id'])) {
                $variant = $unclaimed->get((int) $variantData['id']);
                if ($variant !== null) {
                    // An existing variant's stock moves only through the
                    // ledger (adjust-stock), never from the edit form.
                    unset($variantPayload['stock']);
                    $variant->update($variantPayload);
                    $unclaimed->forget($variant->id);
                    $incomingVariantIds[] = $variant->id;
                }

                continue;
            }

            $match = $this->matchExistingVariant($unclaimed, $variantData);

            if ($match !== null) {
                $fields = array_intersect_key($variantPayload, $variantData);
                unset($fields['stock']);
                $match->update($fields + [
                    'option_values' => $variantPayload['option_values'],
                    'title' => $variantPayload['title'],
                    'position' => $variantPayload['position'],
                ]);
                $unclaimed->forget($match->id);
                $incomingVariantIds[] = $match->id;

                continue;
            }

            $variant = ProductVariant::create($variantPayload);
            $this->recordOpeningVariantStock($variant);
            $incomingVariantIds[] = $variant->id;
        }

        $this->retireVariants(array_values(array_diff($existingVariantIds, $incomingVariantIds)));
    }

    /**
     * Find the not-yet-claimed existing variant an id-less row refers to: the
     * one with the same SKU, else the one with exactly the same option
     * combination (same option names, same values, order ignored).
     *
     * @param  Collection<int, ProductVariant>  $unclaimed
     * @param  array<string, mixed>  $variantData
     */
    private function matchExistingVariant($unclaimed, array $variantData): ?ProductVariant
    {
        $sku = trim((string) ($variantData['sku'] ?? ''));
        if ($sku !== '') {
            $bySku = $unclaimed->first(fn (ProductVariant $v) => $v->sku !== null && strcasecmp(trim($v->sku), $sku) === 0);
            if ($bySku !== null) {
                return $bySku;
            }
        }

        $wanted = $this->normaliseOptions($variantData['option_values'] ?? []);
        if ($wanted === []) {
            return null;
        }

        return $unclaimed->first(fn (ProductVariant $v) => $this->normaliseOptions($v->option_values ?? []) === $wanted);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function normaliseOptions(array $options): array
    {
        $normalised = [];
        foreach ($options as $name => $value) {
            $normalised[trim((string) $name)] = trim((string) $value);
        }
        ksort($normalised);

        return $normalised;
    }

    /**
     * Why a variant cannot be deleted.
     */
    public const VARIANT_IN_USE_MESSAGE = 'This variant holds stock or is referenced by orders, purchase orders, returns or a pending stock adjustment, so it cannot be deleted. Deactivate it instead (set is_active to false).';

    /**
     * Of the given variants, those that must not be deleted: ones holding
     * stock (positive or negative), or referenced by an order, purchase order
     * or return line or a pending stock adjustment request.
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, int>
     */
    public function variantIdsInUse(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $referenced = collect(['order_items', 'purchase_order_items', 'return_order_items', 'stock_adjustment_requests'])
            ->flatMap(fn (string $table) => DB::table($table)->whereIn('product_variant_id', $variantIds)->distinct()->pluck('product_variant_id'))
            ->map(fn ($id) => (int) $id)
            ->all();

        $stocked = ProductVariant::withTrashed()->whereIn('id', $variantIds)->where('stock', '!=', 0)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_merge($referenced, $stocked)));
    }

    /**
     * Remove variants from a product without losing what depends on them. A
     * variant that holds stock, or is referenced by an order, purchase order
     * or return line or a pending stock adjustment request, is deactivated
     * and kept, so its stock and history stay intact; any other variant is
     * soft-deleted.
     *
     * @param  array<int, int>  $variantIds
     */
    private function retireVariants(array $variantIds): void
    {
        if ($variantIds === []) {
            return;
        }

        $keep = $this->variantIdsInUse($variantIds);
        $delete = array_values(array_diff($variantIds, $keep));

        if ($keep !== []) {
            ProductVariant::query()->whereIn('id', $keep)->update(['is_active' => false]);
        }
        if ($delete !== []) {
            ProductVariant::query()->whereIn('id', $delete)->delete();
        }
    }

    /**
     * Map a base64 data-URL's mime type to a file extension.
     */
    private function getImageExtensionFromBase64(string $base64): string
    {
        $mimeType = substr($base64, 5, strpos($base64, ';') - 5);

        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    /**
     * Validate base64 image data: format, size (5 MB), real image content,
     * mime type, and dimensions (4096²). Throws on any failure.
     *
     * @throws InvalidArgumentException
     */
    private function validateBase64Image(string $base64Data): bool
    {
        if (! preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/', $base64Data)) {
            throw new InvalidArgumentException('Invalid image format. Only JPEG, PNG, GIF, and WebP are allowed.');
        }

        $base64Content = substr($base64Data, strpos($base64Data, ',') + 1);
        $imageContent = base64_decode($base64Content, true);

        if ($imageContent === false) {
            throw new InvalidArgumentException('Invalid base64 encoding.');
        }

        $maxSize = 5 * 1024 * 1024;
        if (strlen($imageContent) > $maxSize) {
            throw new InvalidArgumentException('Image size must be less than 5MB.');
        }

        $imageInfo = @getimagesizefromstring($imageContent);
        if ($imageInfo === false) {
            throw new InvalidArgumentException('Invalid image data. File does not appear to be a valid image.');
        }

        $allowedMimes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
        if (! in_array($imageInfo[2], $allowedMimes)) {
            throw new InvalidArgumentException('Invalid image type. Only JPEG, PNG, GIF, and WebP are allowed.');
        }

        $maxDimension = 4096;
        if ($imageInfo[0] > $maxDimension || $imageInfo[1] > $maxDimension) {
            throw new InvalidArgumentException("Image dimensions must not exceed {$maxDimension}x{$maxDimension} pixels.");
        }

        return true;
    }
}
