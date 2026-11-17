<?php

declare(strict_types=1);

namespace App\Imports;

use App\Enums\Permission;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\Supplier;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ProductLocationStockService;
use App\Services\ProductService;
use App\Services\WarehouseAccessService;
use App\Support\ProductCurrencyColumns;
use App\Support\ProductSpreadsheetText;
use App\Support\ProductSpreadsheetValueBinder;
use App\Support\SpreadsheetSafety;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Events\BeforeImport;

/**
 * Import class for processing product data from Excel files.
 *
 * Handles importing and updating products from spreadsheet data,
 * including automatic creation of categories and locations.
 */
final class ProductsImport extends ProductSpreadsheetValueBinder implements SkipsOnFailure, ToCollection, WithChunkReading, WithCustomValueBinder, WithEvents, WithHeadingRow
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
     * The importer's accessible warehouse ids, memoized (false = not yet
     * looked up, null = unrestricted).
     *
     * @var array<int, int>|false|null
     */
    private array|false|null $accessibleWarehouseIds = false;

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
     *
     * A stock change needs manage_stock, is skipped when the organization's
     * approval rules would hold the same adjustment, and a restricted
     * importer draws down only their own warehouses' bins and books units
     * up only into one of their locations. A skipped stock change leaves the
     * row's other columns imported and adds a row warning saying why.
     */
    private function bookImportedStock(Product $product, int $target, int $rowNumber): void
    {
        try {
            // Its own savepoint: a refused movement rolls back only the
            // stock change, never the row's other columns.
            $skipped = DB::transaction(fn (): ?string => $this->moveImportedStock($product, $target));
        } catch (AuthorizationException) {
            $skipped = 'this product\'s stock is not in your accessible warehouses, so the import cannot change it';
        }

        if ($skipped !== null) {
            $this->warn($rowNumber, "Stock not changed: {$skipped}. The other columns were imported.");
        }
    }

    /**
     * @return string|null why the stock change was skipped, or null when it was booked (or there was none)
     *
     * @throws AuthorizationException when a restricted importer's warehouses cannot take the movement
     */
    private function moveImportedStock(Product $product, int $target): ?string
    {
        $locked = Product::withoutGlobalScopes()->whereKey($product->id)->lockForUpdate()->firstOrFail();
        $delta = $target - (int) $locked->stock;

        if ($delta === 0) {
            return null;
        }

        $actor = $this->actingUser();
        if ($actor !== null && ! $actor->hasPermission(Permission::MANAGE_STOCK)) {
            return 'changing stock needs the manage_stock permission';
        }

        if (app(ApprovalService::class)->stockAdjustmentNeedsApproval($locked, null, $delta)) {
            return "a change of {$delta} needs approval under this organization's stock adjustment rules; submit a stock adjustment instead";
        }

        $bins = app(ProductLocationStockService::class);

        // Draw bins down before the total falls (the lazy seed reads the
        // pre-change stock); book them up after it rises.
        if ($delta < 0) {
            $bins->consume($locked, -$delta, actor: $actor);
        } elseif ($actor !== null) {
            $this->authorizeLocation($actor, $locked->location_id === null ? null : (int) $locked->location_id);
        }

        StockAdjustment::adjust(
            $locked,
            $delta,
            'recount',
            'Product import',
            "Stock set to {$target} by import",
            actor: $this->actor,
            // The bins move just above and below.
            syncBins: false,
        );

        if ($delta > 0) {
            $bins->receive($locked, $delta);
        }

        $product->setRawAttributes(array_merge($product->getAttributes(), ['stock' => $target]));
        $product->syncOriginal();

        return null;
    }

    public function registerEvents(): array
    {
        return [BeforeImport::class => [$this, 'configureTextReader']];
    }

    public function configureTextReader(BeforeImport $event): void
    {
        // XLSX readers set values explicitly; their workbook binder must be
        // configured before loading, including when the file is chunked.
        $event->reader->getPhpSpreadsheetReader()->setValueBinder($this);
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            // Absolute row number across chunks (+2 for the header row and the
            // 0-based index).
            $rowNumber = $this->rowOffset + $index + 2;

            try {
                $row = clone $row;
                ProductSpreadsheetText::decodeRow($row);

                // Validate the row
                $currencyColumns = $this->currencyColumns($row->keys()->all(), $rowNumber);

                // Spreadsheet rows do not pass through HTTP's empty-string
                // normalization. Blank optional costs are null, not SQL strings.
                foreach (['purchase_price', 'tax_rate'] as $optional) {
                    if (is_string($row->get($optional)) && trim($row->get($optional)) === '') {
                        $row->put($optional, null);
                    }
                }

                $rules = [
                    'name' => 'required|string|max:255',
                    'sku' => 'required|string|max:255',
                    'price' => 'required|numeric|min:0',
                    'purchase_price' => 'nullable|numeric|min:0',
                    'tax_rate' => 'nullable|numeric|decimal:0,3|min:0|max:100',
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
                if (! ProductSpreadsheetText::isEncoded($row) && str_starts_with($sku, "'")
                    && SpreadsheetSafety::neutralise(substr($sku, 1)) === $sku
                    && ! Product::withTrashed()->where('organization_id', $this->organizationId)->where('sku', $sku)->exists()
                    && Product::withTrashed()->where('organization_id', $this->organizationId)->where('sku', substr($sku, 1))->exists()) {
                    throw new InvalidArgumentException('This SKU is ambiguous with an escaped value from an older export. Export the products again and retain the Inventoros Text Encoding column.');
                }

                if (isset($this->seenSkus[$sku])) {
                    $this->warnings[] = [
                        'row' => $rowNumber,
                        'warnings' => ["Duplicate SKU '{$sku}' in this file; row skipped (first occurrence kept)."],
                    ];

                    continue;
                }
                $this->seenSkus[$sku] = true;

                // Everything this row writes runs in its own (nested)
                // transaction. Laravel Excel wraps each chunk in a
                // transaction; on PostgreSQL one failed statement would abort
                // it, failing every later row and rolling back the earlier
                // ones at commit. Rolling back just this row's savepoint keeps
                // the rest of the file importing.
                $created = DB::transaction(fn () => $this->saveRow($row, $rowNumber, $currencyColumns));
                $created ? $this->imported++ : $this->updated++;
            } catch (\Exception $e) {
                $this->errors[] = [
                    'row' => $rowNumber,
                    'errors' => [$e instanceof QueryException ? 'The row could not be saved due to a database error.' : $e->getMessage()],
                ];

                if ($e instanceof QueryException) {
                    Log::error('Product import row failed with database error', [
                        'organization_id' => $this->organizationId,
                        'row' => $rowNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Advance the absolute-row offset for the next chunk.
        $this->rowOffset += $rows->count();
    }

    /**
     * The importing user: the one given, else the signed-in user. Null only
     * for a system caller, which is not narrowed.
     */
    private function actingUser(): ?User
    {
        $user = $this->actor ?? auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @throws AuthorizationException
     */
    private function requirePermission(User $user, Permission $permission, string $message): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException($message);
        }
    }

    /**
     * The importer's accessible warehouse ids (null when unrestricted),
     * looked up once per import rather than once per row.
     *
     * @return array<int, int>|null
     */
    private function accessibleWarehouseIds(User $user): ?array
    {
        if ($this->accessibleWarehouseIds === false) {
            $this->accessibleWarehouseIds = app(WarehouseAccessService::class)->accessibleWarehouseIds($user);
        }

        return $this->accessibleWarehouseIds;
    }

    private function isRestricted(User $user): bool
    {
        return $this->accessibleWarehouseIds($user) !== null;
    }

    /**
     * The WarehouseAccessService::authorizeLocation() rule, against the
     * memoized warehouse ids.
     *
     * @throws AuthorizationException
     */
    private function authorizeLocation(User $user, ?int $locationId): void
    {
        $ids = $this->accessibleWarehouseIds($user);
        if ($ids === null) {
            return;
        }

        $warehouseId = $locationId === null ? null : ProductLocation::withoutGlobalScopes()->whereKey($locationId)->value('warehouse_id');

        if ($warehouseId === null || ! in_array((int) $warehouseId, $ids, true)) {
            throw new AuthorizationException('You do not have access to this location\'s warehouse.');
        }
    }

    /**
     * Create or update one validated row's product (with its category,
     * location and supplier link).
     *
     * The importer needs create_products to create and edit_products to
     * update or restore a product, and a warehouse-restricted importer may
     * only touch locations in their own warehouses. A refusal throws, which
     * rolls the row back and reports it as a row error.
     *
     * @param  Collection<string, mixed>  $row
     * @param  array<string, string>  $currencyColumns
     * @return bool true when a product was created, false when one was updated
     *
     * @throws AuthorizationException
     */
    private function saveRow(Collection $row, int $rowNumber, array $currencyColumns): bool
    {
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

            // A location the import creates belongs to no warehouse, which a
            // restricted importer cannot use (rolled back with the row).
            $actor = $this->actingUser();
            if ($location->wasRecentlyCreated && $actor !== null && $this->isRestricted($actor)) {
                throw new AuthorizationException("Location '{$row['location']}' does not exist. Locations created by an import belong to no warehouse, so you cannot use them; ask an administrator to create it in one of your warehouses first.");
            }
        }

        // Check if product exists (by SKU) — include soft-deleted rows:
        // the SKU unique index counts them, so a plain lookup would miss
        // a trashed product and then collide on create. Restore + update
        // instead.
        $product = Product::withTrashed()
            ->where('sku', $row['sku'])
            ->where('organization_id', $this->organizationId)
            ->first();

        $actor = $this->actingUser();
        if ($actor !== null) {
            if ($product) {
                $this->requirePermission($actor, Permission::EDIT_PRODUCTS, 'Updating an existing product needs the edit_products permission; the row was skipped.');

                // Moving the product's primary location needs access to both.
                $currentLocationId = $product->location_id === null ? null : (int) $product->location_id;
                if ($locationId === null && $currentLocationId !== null && $this->isRestricted($actor)) {
                    throw new AuthorizationException('The location column is blank, which would remove this product\'s location. You can only keep products in your assigned warehouses, so fill in its location.');
                }
                if ($currentLocationId !== $locationId) {
                    if ($currentLocationId !== null) {
                        $this->authorizeLocation($actor, $currentLocationId);
                    }
                    $this->authorizeLocation($actor, $locationId);
                }
            } else {
                $this->requirePermission($actor, Permission::CREATE_PRODUCTS, 'Creating a product needs the create_products permission; the row was skipped.');

                // Opening stock with no location belongs to no warehouse.
                if ($locationId !== null || (int) $row['stock'] > 0) {
                    $this->authorizeLocation($actor, $locationId);
                }
            }
        }

        // Convert status string to is_active boolean
        $status = $row['status'] ?? 'active';
        $isActive = strtolower($status) === 'active';

        // Marked exports have already been decoded without losing literal
        // identifiers. Every subsequent export still escapes formula cells.
        // Unmarked third-party files retain their existing input sanitization.
        $sanitise = fn ($value) => ProductSpreadsheetText::isEncoded($row) ? $value : SpreadsheetSafety::sanitiseImport($value);

        $productData = [
            'name' => $sanitise($row['name']),
            'sku' => $sanitise($row['sku']),
            'barcode' => $sanitise($row['barcode'] ?? null),
            'description' => $sanitise($row['description'] ?? null),
            'category_id' => $categoryId,
            'location_id' => $locationId,
            'price' => $row['price'],
            // Blank keeps an existing product's currency; a new product gets
            // the organization's (Product::booted).
            'currency' => $row['currency'] ?? $product?->currency,
            'purchase_price' => $row['purchase_price'] ?? null,
            'stock' => $row['stock'],
            'min_stock' => $row['min_stock'] ?? 0,
            'is_active' => $isActive,
            'notes' => $sanitise($row['notes'] ?? null),
            'organization_id' => $this->organizationId,
        ];

        // A file without a tax_rate column leaves the rate alone (files made
        // before the column existed); a blank cell clears it.
        if ($row->has('tax_rate')) {
            $productData['tax_rate'] = $row['tax_rate'];
        }

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
            $this->bookImportedStock($product, (int) $row['stock'], $rowNumber);
            $created = false;
        } else {
            // Create new product; its stock is an opening ledger row.
            $product = Product::create($productData);
            // The row's location was authorized above against the warehouse
            // ids memoized for this import, so don't look them up again.
            app(ProductService::class)->recordOpeningStock($product, $this->actor, locationAuthorized: $this->actingUser() !== null);
            $created = true;
        }

        $this->applyPrimarySupplier($product, $row, $rowNumber);

        return $created;
    }

    /**
     * Link the row's primary supplier (supplier_code, else supplier_name) with
     * its supplier_sku and supplier_cost (in supplier_cost_currency; blank
     * keeps the link's currency, the supplier's for a new link). An unknown
     * supplier, a bad cost or a bad currency is a row warning, never a failed
     * row: the product itself is already saved.
     *
     * @param  Collection<string, mixed>|array<string, mixed>  $row
     */
    protected function applyPrimarySupplier(Product $product, $row, int $rowNumber): void
    {
        $text = fn ($value) => ProductSpreadsheetText::isEncoded($row) ? (string) $value : trim((string) $value);
        $code = $text($row['supplier_code'] ?? '');
        $name = $text($row['supplier_name'] ?? '');
        $sku = $text($row['supplier_sku'] ?? '');
        $cost = trim((string) ($row['supplier_cost'] ?? ''));
        $costCurrency = strtoupper(trim((string) ($row['supplier_cost_currency'] ?? '')));

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

        if ($costCurrency !== '' && preg_match('/^[A-Z]{3}$/', $costCurrency) !== 1) {
            $this->warn($rowNumber, "Invalid supplier_cost_currency '{$costCurrency}' ignored.");
            $costCurrency = '';
        }

        app(ProductService::class)->setPrimarySupplier($product, $supplier->id, [
            'supplier_sku' => $sku !== '' ? mb_substr((string) (ProductSpreadsheetText::isEncoded($row) ? $sku : SpreadsheetSafety::sanitiseImport($sku)), 0, 255) : null,
            'cost_price' => $costPrice,
            'cost_currency' => $costCurrency !== '' ? $costCurrency : null,
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
        $base = mb_strtoupper(mb_substr($name, 0, 3));
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
