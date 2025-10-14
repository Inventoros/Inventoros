<?php

namespace App\Exports;

use App\Models\Inventory\Product;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductsExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    protected $organizationId;
    protected $filters;

    public function __construct($organizationId, array $filters = [])
    {
        $this->organizationId = $organizationId;
        $this->filters = $filters;
    }

    /**
     * Query for products to export
     */
    public function query()
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
            $query->where('status', $this->filters['status']);
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
            $product->status,
            $product->notes,
            $product->created_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Style the worksheet
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
