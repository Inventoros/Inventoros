<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\AppliesOrganizationMailConfig;
use App\Mail\Concerns\UsesOrganizationBranding;
use App\Models\Purchasing\PurchaseOrder;
use App\Services\Documents\DocumentPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sends a purchase order to the supplier with the PO PDF attached.
 *
 * The PDF is rendered in build(), in the queue worker, from the same service
 * the download route uses, so the supplier gets exactly what staff download.
 */
class PurchaseOrderEmail extends Mailable
{
    use AppliesOrganizationMailConfig, Queueable, SerializesModels, UsesOrganizationBranding;

    /**
     * Carries the organization id for the mail-config and branding concerns.
     *
     * @var array{organization_id: int}
     */
    public array $data;

    public function __construct(public PurchaseOrder $purchaseOrder, public ?string $customMessage = null)
    {
        $this->data = ['organization_id' => (int) $purchaseOrder->organization_id];
    }

    /**
     * @return $this
     */
    public function build()
    {
        $this->applyOrganizationMailConfig();

        $branding = $this->organizationBranding();
        $pdfs = app(DocumentPdfService::class);
        $purchaseOrder = $this->purchaseOrder->loadMissing(['items', 'supplier', 'organization']);

        if ($branding['brandEmail']) {
            $this->replyTo($branding['brandEmail'], $branding['brandName']);
        }

        return $this->subject("Purchase Order {$purchaseOrder->po_number} from {$branding['brandName']}")
            ->view('emails.purchase-order')
            ->text('emails.text.purchase-order')
            ->with($branding + [
                'emailType' => 'purchase_order',
                'transactional' => true,
            ])
            ->attachData(
                $pdfs->purchaseOrder($purchaseOrder)->output(),
                $pdfs->purchaseOrderFilename($purchaseOrder),
                ['mime' => 'application/pdf']
            );
    }
}
