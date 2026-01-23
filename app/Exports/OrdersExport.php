<?php

namespace App\Exports;

use App\Models\Order\Order;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrdersExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    protected $organizationId;
    protected $filters;

    public function __construct($organizationId, array $filters = [])
    {
        $this->organizationId = $organizationId;
        $this->filters = $filters;
    }

    /**
     * Query for orders to export
     */
    public function query()
    {
        $query = Order::query()
            ->with(['items.product', 'customer'])
            ->forOrganization($this->organizationId);

        // Apply filters
        if (!empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }

        if (!empty($this->filters['date_from'])) {
            $query->whereDate('created_at', '>=', $this->filters['date_from']);
        }

        if (!empty($this->filters['date_to'])) {
            $query->whereDate('created_at', '<=', $this->filters['date_to']);
        }

        if (!empty($this->filters['customer_id'])) {
            $query->where('customer_id', $this->filters['customer_id']);
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Define the column headings
     */
    public function headings(): array
    {
        return [
            'Order ID',
            'Order Number',
            'Customer',
            'Customer Email',
            'Status',
            'Subtotal',
            'Tax',
            'Discount',
            'Total',
            'Currency',
            'Items Count',
            'Notes',
            'Created At',
            'Updated At',
        ];
    }

    /**
     * Map each order to the export format
     */
    public function map($order): array
    {
        return [
            $order->id,
            $order->order_number,
            $order->customer ? $order->customer->name : ($order->customer_name ?? 'N/A'),
            $order->customer ? $order->customer->email : ($order->customer_email ?? ''),
            $order->status,
            $order->subtotal,
            $order->tax,
            $order->discount,
            $order->total,
            $order->currency ?? 'USD',
            $order->items->count(),
            $order->notes,
            $order->created_at->format('Y-m-d H:i:s'),
            $order->updated_at->format('Y-m-d H:i:s'),
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
