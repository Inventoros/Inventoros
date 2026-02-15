<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Inventory\Product;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export class for generating product data Excel files.
 *
 * Handles exporting product data with optional filtering by category, location, status, and stock level.
 */
final class ProductsExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    /**
     * The organization ID to filter products by.
     *
     * @var int
     */
    protected $organizationId;

    /**
     * Filters to apply to the export query.
     *
     * @var array
     */
    protected $filters;

    /**
     * Create a new export instance.
     *
     * @param int $organizationId The organization to export products from
     * @param array $filters Optional filters (category_id, location_id, status, low_stock)
     */
    public function __construct($organizationId, array $filters = [])
    {
        $this->organizationId = $organizationId;
        $this->filters = $filters;
    }

    /**
     * Query for products to export
     */
    public function query(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Product::query()
            ->with(['category', 'location'])
            ->forOrganization($this->organizationId);

        // Apply filters
        if (!empty($this->filters['category_id'])) {
            $query->where('category_id', $this->filters['category_id']);
        }

        if (!empty($this->filters['location_id'])) {
            $query->where('location_id', $this->filters['location_id']);
        }

        if (!empty($this->filters['status'])) {
            // Convert status to is_active boolean
            $isActive = $this->filters['status'] === 'active';
            $query->where('is_active', $isActive);
        }

        if (!empty($this->filters['low_stock'])) {
            $query->whereRaw('stock <= min_stock');
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Define the column headings
     */
    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'SKU',
            'Barcode',
            'Description',
            'Category',
            'Location',
            'Price',
            'Currency',
            'Purchase Price',
            'Stock',
            'Min Stock',
            'Status',
            'Notes',
            'Created At',
        ];
    }

    /**
     * Map each product to the export format
     */
    public function map($product): array
    {
        return [
            $product->id,
            $product->name,
            $product->sku,
            $product->barcode,
            $product->description,
            $product->category ? $product->category->name : '',
            $product->location ? $product->location->name : '',
            $product->price,
            $product->currency,
            $product->purchase_price,
            $product->stock,
            $product->min_stock,
            $product->is_active ? 'active' : 'inactive',
            $product->notes,
            $product->created_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Style the worksheet
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
