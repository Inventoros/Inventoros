<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Controller for generating order invoice PDFs.
 *
 * Handles PDF generation, download, and preview for order invoices.
 */
class InvoiceController extends Controller
{
    /**
     * Download the invoice PDF for an order.
     *
     * @param Request $request
     * @param Order $order
     * @return Response
     */
    public function download(Request $request, Order $order): Response
    {
        $this->authorizeOrder($request, $order);

        $order->load(['items', 'organization']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $order,
            'organization' => $order->organization,
            'invoiceNumber' => 'INV-' . $order->order_number,
            'generatedDate' => now()->format('F j, Y'),
        ]);

        $filename = 'INV-' . $order->order_number . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Preview the invoice PDF for an order in the browser.
     *
     * @param Request $request
     * @param Order $order
     * @return Response
     */
    public function preview(Request $request, Order $order): Response
    {
        $this->authorizeOrder($request, $order);

        $order->load(['items', 'organization']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $order,
            'organization' => $order->organization,
            'invoiceNumber' => 'INV-' . $order->order_number,
            'generatedDate' => now()->format('F j, Y'),
        ]);

        $filename = 'INV-' . $order->order_number . '.pdf';

        return $pdf->stream($filename);
    }

    /**
     * Authorize that the user can access this order's invoice.
     *
     * @param Request $request
     * @param Order $order
     * @return void
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    private function authorizeOrder(Request $request, Order $order): void
    {
        $user = $request->user();

        if ($order->organization_id !== $user->organization_id) {
            abort(403, 'Unauthorized access to this order.');
        }
    }
}
