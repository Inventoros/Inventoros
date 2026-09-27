<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\AppliesOrganizationMailConfig;
use App\Mail\Concerns\UsesOrganizationBranding;
use App\Models\Shipping\Shipment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the order's customer that a shipment is on its way, with the carrier,
 * tracking number and tracking link. Queued; the organization's mail config
 * and branding are applied in the worker.
 */
class ShipmentShippedEmail extends Mailable implements ShouldQueue
{
    use AppliesOrganizationMailConfig, Queueable, SerializesModels, UsesOrganizationBranding;

    /**
     * Carries the organization id for the mail-config and branding concerns.
     *
     * @var array{organization_id: int}
     */
    public array $data;

    public function __construct(public Shipment $shipment)
    {
        $this->data = ['organization_id' => (int) $shipment->organization_id];
    }

    /**
     * @return $this
     */
    public function build()
    {
        $this->applyOrganizationMailConfig();

        $branding = $this->organizationBranding();
        $shipment = $this->shipment->loadMissing('items.orderItem', 'order');
        $order = $shipment->order;

        if ($branding['brandEmail']) {
            $this->replyTo($branding['brandEmail'], $branding['brandName']);
        }

        return $this->subject("Your order #{$order->order_number} has shipped")
            ->view('emails.shipment-shipped')
            ->text('emails.text.shipment-shipped')
            ->with($branding + [
                'order' => $order,
                'carrierLabel' => trim(($shipment->carrier_name ?? '').' '.($shipment->service ?? '')),
                'emailType' => 'shipment_shipped',
                'transactional' => true,
            ]);
    }
}
