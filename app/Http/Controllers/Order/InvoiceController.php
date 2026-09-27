<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Exceptions\DocumentEmailException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendDocumentEmailRequest;
use App\Models\Order\Order;
use App\Services\Documents\DocumentPdfService;
use App\Services\OrderInvoiceEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Controller for order invoices.
 *
 * Handles PDF download, preview and emailing the invoice to the customer.
 * The first time an invoice is generated the order is given its invoice
 * number (see OrderInvoiceNumberService).
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly DocumentPdfService $pdfs) {}

    /**
     * Download the invoice PDF for an order.
     */
    public function download(Request $request, Order $order): Response
    {
        $this->authorizeOrder($request, $order);

        $pdf = $this->pdfs->orderInvoice($order);

        return $pdf->download($this->pdfs->orderInvoiceFilename($order));
    }

    /**
     * Preview the invoice PDF for an order in the browser.
     */
    public function preview(Request $request, Order $order): Response
    {
        $this->authorizeOrder($request, $order);

        $pdf = $this->pdfs->orderInvoice($order);

        return $pdf->stream($this->pdfs->orderInvoiceFilename($order));
    }

    /**
     * Email the invoice PDF to the order's customer.
     */
    public function email(SendDocumentEmailRequest $request, Order $order, OrderInvoiceEmailService $emails): RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        try {
            $order = $emails->send(
                $order,
                $request->user(),
                $request->recipient(),
                $request->ccList(),
                $request->customMessage(),
            );
        } catch (DocumentEmailException $e) {
            return redirect()->route('orders.show', $order)->with('error', $e->getMessage());
        }

        return redirect()->route('orders.show', $order)
            ->with('success', "Invoice {$order->invoice_number} emailed to {$order->invoice_sent_to}.");
    }

    /**
     * Authorize that the user can access this order's invoice.
     *
     * @throws HttpException
     */
    private function authorizeOrder(Request $request, Order $order): void
    {
        $user = $request->user();

        if ($order->organization_id !== $user->organization_id) {
            abort(403, 'Unauthorized access to this order.');
        }
    }
}
