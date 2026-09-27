<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderApprovalStatus;
use App\Enums\OrderStatus;
use App\Mail\LowStockEmail;
use App\Mail\OrderApprovalEmail;
use App\Mail\OrderStatusEmail;
use App\Mail\TestEmail;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\Setting;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Tests\TestCase;

/**
 * Every notification mailable ships a plain-text alternative and is branded
 * with the sending organization's name, not the app's.
 */
class MailablePlainTextAndBrandingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Northwind Supply', 'email' => 'hello@northwind.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        // Rendering resolves the org's mailer; use one that needs no host.
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.provider', 'value' => 'array', 'encrypted' => false]);
        config(['app.name' => 'Inventoros']);
    }

    private function order(): Order
    {
        $approver = User::create([
            'name' => 'Ann Approver', 'email' => 'ann@northwind.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        return Order::create([
            'organization_id' => $this->org->id,
            'order_number' => 'ORD-20260927-0042',
            'source' => 'manual',
            'customer_name' => 'Casey Customer',
            'status' => OrderStatus::SHIPPED,
            'approval_status' => OrderApprovalStatus::APPROVED,
            'approved_by' => $approver->id,
            'approval_notes' => 'Looks good',
            'subtotal' => 20, 'tax' => 0, 'shipping' => 0, 'total' => 20,
            'currency' => 'USD',
            'order_date' => now(),
        ]);
    }

    /**
     * @return array<string, Mailable>
     */
    private function mailables(): array
    {
        // Fixtures are created once; each call returns fresh mailables.
        $product = Product::firstWhere('sku', 'LOW-1') ?? Product::create([
            'organization_id' => $this->org->id, 'sku' => 'LOW-1', 'name' => 'Hex Bolt',
            'price' => 1, 'currency' => 'USD', 'stock' => 1, 'min_stock' => 10, 'is_active' => true,
        ]);
        $order = Order::firstWhere('order_number', 'ORD-20260927-0042') ?? $this->order();

        return [
            'low stock' => new LowStockEmail([
                'organization_id' => $this->org->id, 'product' => $product, 'notification_url' => 'https://app.test/p/1',
            ]),
            'order status' => new OrderStatusEmail([
                'organization_id' => $this->org->id, 'order' => $order,
                'old_status' => OrderStatus::PROCESSING, 'notification_url' => 'https://app.test/o/1',
            ]),
            'order approval' => new OrderApprovalEmail([
                'organization_id' => $this->org->id, 'order' => $order, 'notification_url' => 'https://app.test/o/1',
            ]),
            'test email' => new TestEmail([
                'organization_id' => $this->org->id, 'organization' => $this->org->name, 'tested_by' => 'Ann Approver',
            ]),
        ];
    }

    public function test_every_notification_mailable_has_a_plain_text_part(): void
    {
        foreach ($this->mailables() as $name => $mailable) {
            $mailable->build();

            $this->assertNotNull($mailable->textView, "{$name} has no plain-text view");
            $this->assertTrue(view()->exists($mailable->textView), "{$name} text view is missing");
        }
    }

    public function test_every_notification_mailable_is_branded_with_the_org_name(): void
    {
        foreach ($this->mailables() as $mailable) {
            $mailable->assertSeeInHtml('Northwind Supply');
            $mailable->assertSeeInText('Northwind Supply');
            $mailable->assertDontSeeInHtml('Inventoros. All rights reserved.');
        }
    }

    public function test_plain_text_parts_carry_the_key_details(): void
    {
        $mailables = $this->mailables();

        $mailables['low stock']->assertSeeInText('Hex Bolt');
        $mailables['low stock']->assertSeeInText('https://app.test/p/1');
        $mailables['order status']->assertSeeInText('ORD-20260927-0042');
        $mailables['order status']->assertSeeInText('Shipped');
        $mailables['order status']->assertSeeInText('Processing');
        $mailables['order approval']->assertSeeInText('ORD-20260927-0042');
        $mailables['order approval']->assertSeeInText('Approved');
        $mailables['order approval']->assertSeeInText('Looks good');
        $mailables['test email']->assertSeeInText('Ann Approver');
    }

    public function test_order_emails_render_with_enum_statuses(): void
    {
        $mailables = $this->mailables();

        // The order casts status and approval_status to enums; the templates
        // and the approval subject must cope with that rather than crash.
        $mailables['order approval']->build();
        $this->assertStringContainsString('Approved', $mailables['order approval']->subject);

        $mailables = $this->mailables();
        $mailables['order approval']->assertSeeInHtml('Order Approved');
        $mailables['order status']->assertSeeInHtml('Shipped');
    }
}
