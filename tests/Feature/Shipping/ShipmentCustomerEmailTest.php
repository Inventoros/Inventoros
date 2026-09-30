<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Mail\ShipmentShippedEmail;
use App\Models\Setting;
use App\Models\Shipping\Shipment;
use App\Services\Shipping\ShipmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ShipmentCustomerEmailTest extends TestCase
{
    use RefreshDatabase;
    use ShippingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShipping();
        Setting::create(['organization_id' => $this->organization->id, 'key' => 'email.provider', 'value' => 'array', 'encrypted' => false]);
    }

    private function shipment(array $data = []): Shipment
    {
        return app(ShipmentService::class)->create($this->makeOrder(), array_merge([
            'carrier' => 'manual',
            'carrier_name' => 'UPS',
            'tracking_number' => '1Z999AA10123456784',
            'notify_customer' => true,
        ], $data), $this->admin);
    }

    public function test_the_customer_is_emailed_once_when_the_shipment_leaves(): void
    {
        Mail::fake();
        $shipment = $this->shipment();
        $service = app(ShipmentService::class);

        $service->markShipped($shipment, $this->admin);
        $service->applyTrackingStatus($shipment->fresh(), \App\Enums\ShipmentStatus::IN_TRANSIT);

        Mail::assertQueued(ShipmentShippedEmail::class, 1);
        Mail::assertQueued(ShipmentShippedEmail::class, fn (ShipmentShippedEmail $mail) => $mail->hasTo('casey@customer.test'));
        // Claimed and queued; customer_notified_at waits for delivery.
        $this->assertNotNull($shipment->fresh()->customer_notification_queued_at);
        $this->assertNull($shipment->fresh()->customer_notified_at);
    }

    public function test_customer_notified_at_is_stamped_when_the_email_is_delivered(): void
    {
        $shipment = $this->shipment();

        app(ShipmentService::class)->markShipped($shipment, $this->admin);

        $fresh = $shipment->fresh();
        $this->assertNotNull($fresh->customer_notification_queued_at);
        $this->assertNotNull($fresh->customer_notified_at);
    }

    public function test_a_shipment_notified_before_the_queued_column_existed_is_not_emailed_again(): void
    {
        Mail::fake();
        $shipment = $this->shipment();
        $shipment->forceFill(['customer_notified_at' => now()->subDay()])->save();

        app(ShipmentService::class)->markShipped($shipment->fresh(), $this->admin);

        Mail::assertNothingQueued();
    }

    public function test_no_email_when_notifications_are_off_or_there_is_no_address(): void
    {
        Mail::fake();
        $service = app(ShipmentService::class);

        $service->markShipped($this->shipment(['notify_customer' => false]), $this->admin);

        $order = $this->makeOrder([[$this->widget, 1]], ['customer_email' => null]);
        $silent = $service->create($order, ['carrier' => 'manual', 'notify_customer' => true], $this->admin);
        $service->markShipped($silent, $this->admin);

        Mail::assertNothingQueued();
    }

    public function test_the_email_is_queued_and_carries_the_tracking_link_in_both_parts(): void
    {
        $shipment = $this->shipment();
        $mail = new ShipmentShippedEmail($shipment);

        $this->assertInstanceOf(ShouldQueue::class, $mail);

        $mail->assertSeeInHtml('1Z999AA10123456784');
        $mail->assertSeeInHtml('https://www.ups.com/track?tracknum=1Z999AA10123456784');
        $mail->assertSeeInHtml('Ship Org');
        $mail->assertSeeInText('1Z999AA10123456784');
        $mail->assertSeeInText('https://www.ups.com/track?tracknum=1Z999AA10123456784');
        $mail->assertSeeInText('Widget');
        $mail->assertSeeInText('Ship Org');

        $mail->build();
        $this->assertStringContainsString($shipment->order->order_number, $mail->subject);
        $this->assertSame('emails.text.shipment-shipped', $mail->textView);
    }
}
