<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\AppliesOrganizationMailConfig;
use App\Mail\Concerns\UsesOrganizationBranding;
use App\Models\Order\Order;
use App\Services\Documents\DocumentPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sends an order's invoice to the customer with the invoice PDF attached.
 *
 * The PDF is rendered in build(), in the queue worker, by the same service
 * the invoice download route uses.
 */
class OrderInvoiceEmail extends Mailable
{
    use AppliesOrganizationMailConfig, Queueable, SerializesModels, UsesOrganizationBranding;

    /**
     * Carries the organization id for the mail-config and branding concerns.
     *
     * @var array{organization_id: int}
     */
    public array $data;

    public function __construct(public Order $order, public ?string $customMessage = null)
    {
        $this->data = ['organization_id' => (int) $order->organization_id];
    }

    /**
     * @return $this
     */
    public function build()
    {
        $this->applyOrganizationMailConfig();

        $branding = $this->organizationBranding();
        $pdfs = app(DocumentPdfService::class);

        // Rendering the PDF assigns the invoice number if it somehow has none.
        $pdf = $pdfs->orderInvoice($this->order);
        $order = $this->order->loadMissing('items');

        if ($branding['brandEmail']) {
            $this->replyTo($branding['brandEmail'], $branding['brandName']);
        }

        return $this->subject("Invoice {$order->invoice_number} from {$branding['brandName']}")
            ->view('emails.order-invoice')
            ->text('emails.text.order-invoice')
            ->with($branding + [
                'emailType' => 'order_invoice',
                'transactional' => true,
            ])
            ->attachData($pdf->output(), $pdfs->orderInvoiceFilename($order), ['mime' => 'application/pdf']);
    }
}
