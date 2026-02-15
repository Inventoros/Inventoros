<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsFailures;

/**
 * Import class for processing product data from Excel files.
 *
 * Handles importing and updating products from spreadsheet data,
 * including automatic creation of categories and locations.
 */
final class ProductsImport implements ToCollection, WithHeadingRow, SkipsOnFailure
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
     * Create a new import instance.
     *
     * @param int $organizationId The organization to import products into
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
                        'row' => $index + 2, // +2 for header and 0-index
                        'errors' => $validator->errors()->all(),
                    ];
                    continue;
                }

                // Find or create category
                $categoryId = null;
                if (!empty($row['category'])) {
                    $category = ProductCategory::firstOrCreate(
                        [
                            'name' => $row['category'],
                            'organization_id' => $this->organizationId,
                        ]
                    );
                    $categoryId = $category->id;
                }

                // Find or create location
                $locationId = null;
                if (!empty($row['location'])) {
                    $location = ProductLocation::firstOrCreate(
                        [
                            'name' => $row['location'],
                            'organization_id' => $this->organizationId,
                        ],
                        [
                            'code' => strtoupper(substr($row['location'], 0, 3)),
                        ]
                    );
                    $locationId = $location->id;
                }

                // Check if product exists (by SKU)
                $product = Product::where('sku', $row['sku'])
                    ->where('organization_id', $this->organizationId)
                    ->first();

                // Convert status string to is_active boolean
                $status = $row['status'] ?? 'active';
                $isActive = strtolower($status) === 'active';

                $productData = [
                    'name' => $row['name'],
                    'sku' => $row['sku'],
                    'barcode' => $row['barcode'] ?? null,
                    'description' => $row['description'] ?? null,
                    'category_id' => $categoryId,
                    'location_id' => $locationId,
                    'price' => $row['price'],
                    'currency' => $row['currency'] ?? 'USD',
                    'purchase_price' => $row['purchase_price'] ?? null,
                    'stock' => $row['stock'],
                    'min_stock' => $row['min_stock'] ?? 0,
                    'is_active' => $isActive,
                    'notes' => $row['notes'] ?? null,
                    'organization_id' => $this->organizationId,
                ];

                if ($product) {
                    // Update existing product
                    $product->update($productData);
                    $this->updated++;
                } else {
                    // Create new product
                    Product::create($productData);
                    $this->imported++;
                }
            } catch (\Exception $e) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'errors' => [$e->getMessage()],
                ];
            }
        }
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
        ];
    }
}
