<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\Documents\DocumentPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Controller for generating purchase order invoice PDFs.
 *
 * Handles PDF download and preview; the PDF itself comes from
 * DocumentPdfService, which the supplier email attaches too.
 */
class PurchaseOrderInvoiceController extends Controller
{
    public function __construct(private readonly DocumentPdfService $pdfs) {}

    /**
     * Download the invoice PDF for a purchase order.
     */
    public function download(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorizePurchaseOrder($request, $purchaseOrder);

        return $this->pdfs->purchaseOrder($purchaseOrder)
            ->download($this->pdfs->purchaseOrderFilename($purchaseOrder));
    }

    /**
     * Preview the invoice PDF for a purchase order in the browser.
     */
    public function preview(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorizePurchaseOrder($request, $purchaseOrder);

        return $this->pdfs->purchaseOrder($purchaseOrder)
            ->stream($this->pdfs->purchaseOrderFilename($purchaseOrder));
    }

    /**
     * Authorize that the user can access this purchase order's invoice.
     *
     * @throws HttpException
     */
    private function authorizePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder): void
    {
        $user = $request->user();

        if ($purchaseOrder->organization_id !== $user->organization_id) {
            abort(403, 'Unauthorized access to this purchase order.');
        }
    }
}
