<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Order\Order;
use App\Models\Purchasing\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * Builds the PDF documents the app hands out: purchase orders (for suppliers)
 * and order invoices (for customers). Shared by the download/preview routes
 * and by the mailables that attach the same PDF, so what a user downloads is
 * exactly what the recipient receives.
 */
class DocumentPdfService
{
    public function __construct(private readonly OrderInvoiceNumberService $invoiceNumbers) {}

    public function purchaseOrder(PurchaseOrder $purchaseOrder): DomPdf
    {
        $purchaseOrder->loadMissing(['items', 'organization', 'supplier']);

        return Pdf::loadView('pdf.purchase-order-invoice', [
            'purchaseOrder' => $purchaseOrder,
            'organization' => $purchaseOrder->organization,
            'supplier' => $purchaseOrder->supplier,
            'generatedDate' => now()->format('F j, Y'),
        ]);
    }

    public function purchaseOrderFilename(PurchaseOrder $purchaseOrder): string
    {
        return $purchaseOrder->po_number.'.pdf';
    }

    /**
     * Render an order's invoice. The first time an invoice is generated for
     * an order it is assigned its invoice number, which it keeps from then on.
     */
    public function orderInvoice(Order $order): DomPdf
    {
        return Pdf::loadView('pdf.invoice', $this->orderInvoiceViewData($order));
    }

    /**
     * The data the invoice view renders: the order with its lines, the
     * payments and refunds that still stand (voided ones are left off the
     * customer's document), and the discount and balance figures.
     *
     * @return array<string, mixed>
     */
    public function orderInvoiceViewData(Order $order): array
    {
        $this->invoiceNumbers->ensureAssigned($order);

        $order->loadMissing(['items', 'organization']);

        return [
            'order' => $order,
            'organization' => $order->organization,
            'invoiceNumber' => $order->invoice_number,
            'generatedDate' => now()->format('F j, Y'),
            'lineDiscountTotal' => $order->lineDiscountTotal(),
            'orderDiscountAmount' => $order->orderDiscountAmount(),
            'payments' => $order->payments()->withoutGlobalScopes()->active()->orderBy('paid_at')->orderBy('id')->get(),
            'balanceDue' => $order->balanceDue(),
        ];
    }

    public function orderInvoiceFilename(Order $order): string
    {
        $this->invoiceNumbers->ensureAssigned($order);

        return $order->invoice_number.'.pdf';
    }
}
