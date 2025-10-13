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

    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
    }

    /**
     * Query for products to export
     */
    public function query()
    {
        return Product::query()
            ->with(['category', 'location'])
            ->forOrganization($this->organizationId)
            ->orderBy('created_at', 'desc');
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
