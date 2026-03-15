<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Purchasing\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Controller for generating purchase order invoice PDFs.
 *
 * Handles PDF generation, download, and preview for purchase order invoices.
 */
class PurchaseOrderInvoiceController extends Controller
{
    /**
     * Download the invoice PDF for a purchase order.
     *
     * @param Request $request
     * @param PurchaseOrder $purchaseOrder
     * @return Response
     */
    public function download(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorizePurchaseOrder($request, $purchaseOrder);

        $purchaseOrder->load(['items', 'organization', 'supplier']);

        $pdf = Pdf::loadView('pdf.purchase-order-invoice', [
            'purchaseOrder' => $purchaseOrder,
            'organization' => $purchaseOrder->organization,
            'supplier' => $purchaseOrder->supplier,
            'generatedDate' => now()->format('F j, Y'),
        ]);

        $filename = $purchaseOrder->po_number . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Preview the invoice PDF for a purchase order in the browser.
     *
     * @param Request $request
     * @param PurchaseOrder $purchaseOrder
     * @return Response
     */
    public function preview(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorizePurchaseOrder($request, $purchaseOrder);

        $purchaseOrder->load(['items', 'organization', 'supplier']);

        $pdf = Pdf::loadView('pdf.purchase-order-invoice', [
            'purchaseOrder' => $purchaseOrder,
            'organization' => $purchaseOrder->organization,
            'supplier' => $purchaseOrder->supplier,
            'generatedDate' => now()->format('F j, Y'),
        ]);

        $filename = $purchaseOrder->po_number . '.pdf';

        return $pdf->stream($filename);
    }

    /**
     * Authorize that the user can access this purchase order's invoice.
     *
     * @param Request $request
     * @param PurchaseOrder $purchaseOrder
     * @return void
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    private function authorizePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder): void
    {
        $user = $request->user();

        if ($purchaseOrder->organization_id !== $user->organization_id) {
            abort(403, 'Unauthorized access to this purchase order.');
        }
    }
}
