<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Support\SpreadsheetSafety;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Line-items order export: one row per order line.
 *
 * Format (documented on the Import/Export page too):
 *  - Every row carries the order's header fields (number, external reference,
 *    date, status, customer, currency, notes) AND the order totals (subtotal,
 *    tax, shipping, total), repeated on each of the order's lines. Repeating
 *    rather than filling only the first row keeps every row self-contained, so
 *    the sheet can be sorted, filtered or pivoted without losing which order a
 *    line belongs to. To sum order totals, count each Order Number once (or
 *    only rows where Line = 1).
 *  - "Line" is the line's 1-based position within its order.
 *  - Product SKU is the parent product's SKU; Variant SKU / Variant Title are
 *    filled only for lines sold as a variant. Name and SKU fall back to the
 *    snapshot stored on the line when the product has since been deleted.
 *
 * Accepts the same filters as OrdersExport (status, date_from, date_to,
 * customer_id), applied to the order.
 */
final class OrderLinesExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly int $organizationId,
        private readonly array $filters = [],
    ) {}

    /**
     * Order lines of this organization's (non-deleted) orders, newest order
     * first, lines in entry order.
     */
    public function query(): Builder
    {
        $query = OrderItem::query()
            ->select('order_items.*')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.organization_id', $this->organizationId)
            ->whereNull('orders.deleted_at')
            ->with([
                'order' => fn ($q) => $q->withoutGlobalScopes()->with([
                    'customer' => fn ($c) => $c->withoutGlobalScopes(),
                    'items' => fn ($i) => $i->select('id', 'order_id')->orderBy('id'),
                ]),
                'product' => fn ($q) => $q->withoutGlobalScopes(),
                'variant' => fn ($q) => $q->withoutGlobalScopes(),
            ]);

        if (! empty($this->filters['status'])) {
            $query->where('orders.status', $this->filters['status']);
        }

        if (! empty($this->filters['date_from'])) {
            $query->whereDate('orders.created_at', '>=', $this->filters['date_from']);
        }

        if (! empty($this->filters['date_to'])) {
            $query->whereDate('orders.created_at', '<=', $this->filters['date_to']);
        }

        if (! empty($this->filters['customer_id'])) {
            $query->where('orders.customer_id', $this->filters['customer_id']);
        }

        return $query
            ->orderBy('orders.created_at', 'desc')
            ->orderBy('orders.id', 'desc')
            ->orderBy('order_items.id');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Order Number',
            'External Reference',
            'Order Date',
            'Status',
            'Customer Name',
            'Customer Email',
            'Currency',
            'Line',
            'Product SKU',
            'Product Name',
            'Variant SKU',
            'Variant Title',
            'Quantity',
            'Unit Price',
            'Line Tax',
            'Line Total',
            'Order Subtotal',
            'Order Tax',
            'Order Shipping',
            'Order Total',
            'Notes',
        ];
    }

    /**
     * @param  OrderItem  $item
     * @return array<int, mixed>
     */
    public function map($item): array
    {
        /** @var Order $order */
        $order = $item->order;
        $variant = $item->product_variant_id !== null ? $item->variant : null;
        $isVariantLine = $item->product_variant_id !== null;

        $line = $order->items->search(fn ($other) => $other->id === $item->id);
        $orderDate = $order->order_date ?? $order->created_at;

        return SpreadsheetSafety::neutraliseRow([
            $order->order_number,
            $order->external_reference ?? '',
            $orderDate?->format('Y-m-d H:i:s') ?? '',
            $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status,
            $order->customer?->name ?? ($order->customer_name ?? ''),
            $order->customer?->email ?? ($order->customer_email ?? ''),
            $order->currency ?? 'USD',
            $line === false ? '' : $line + 1,
            $item->product?->sku ?? ($isVariantLine ? '' : ($item->sku ?? '')),
            $item->product_name ?? ($item->product?->name ?? ''),
            $isVariantLine ? ($variant?->sku ?? $item->sku ?? '') : '',
            $isVariantLine ? ($variant?->title ?? '') : '',
            $item->quantity,
            $item->unit_price,
            $item->tax,
            $item->total,
            $order->subtotal,
            $order->tax,
            $order->shipping,
            $order->total,
            $order->notes ?? '',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
