<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Documents\DocumentPdfService;
use App\Services\OrderPaymentService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invoice PDF shows the discounts that make up its total, the payments
 * received against it, and the balance still due.
 */
class InvoicePaymentsAndDiscountsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $organization = Organization::create([
            'name' => 'Invoice Org', 'email' => 'invoice@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->product = Product::create([
            'organization_id' => $organization->id, 'sku' => 'INV-1', 'name' => 'Invoiced Widget',
            'price' => 50.00, 'currency' => 'USD', 'stock' => 100, 'min_stock' => 0, 'is_active' => true,
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@invoice.test', 'password' => bcrypt('password'),
            'organization_id' => $organization->id, 'role' => 'admin',
        ]);
    }

    private function html(Order $order): string
    {
        $service = app(DocumentPdfService::class);

        return view('pdf.invoice', $service->orderInvoiceViewData($order->fresh()))->render();
    }

    public function test_the_customer_portal_invoice_hides_payment_references(): void
    {
        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 50]],
        ], $this->admin);
        app(OrderPaymentService::class)->record($order, $this->admin, ['amount' => 30, 'method' => 'card', 'reference' => 'TX-77']);

        $service = app(DocumentPdfService::class);
        $portal = view('pdf.invoice', $service->orderInvoiceViewData($order->fresh(), forCustomerPortal: true))->render();

        $this->assertStringNotContainsString('TX-77', $portal);
        $this->assertStringContainsString('USD 30.00', $portal); // the payment itself is still listed
        $this->assertStringContainsString('TX-77', $this->html($order)); // staff copy keeps it
    }

    public function test_invoice_shows_discounts_payments_and_balance_due(): void
    {
        // 2 x 50 = 100 gross, 10% line discount (10), 5.00 order discount, 4.50 tax.
        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'tax' => 4.50, 'discount_type' => 'fixed', 'discount_value' => 5,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 50, 'discount_type' => 'percent', 'discount_value' => 10]],
        ], $this->admin);

        $payments = app(OrderPaymentService::class);
        $payments->record($order, $this->admin, ['amount' => 30, 'method' => 'card', 'reference' => 'TX-77']);
        $voided = $payments->record($order, $this->admin, ['amount' => 20, 'method' => 'cash']);
        $payments->void($voided, $this->admin, 'entered twice');

        $html = $this->html($order);

        $this->assertStringContainsString('Line discounts', $html);
        $this->assertStringContainsString('-USD 10.00', $html);
        $this->assertStringContainsString('Order discount', $html);
        $this->assertStringContainsString('-USD 5.00', $html);
        $this->assertStringContainsString('USD 89.50', $html); // total
        $this->assertStringContainsString('TX-77', $html);
        $this->assertStringContainsString('Amount paid', $html);
        $this->assertStringContainsString('USD 30.00', $html);
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('USD 59.50', $html);
        $this->assertStringNotContainsString('USD 20.00', $html, 'a voided payment is not shown');
    }

    public function test_invoice_without_discounts_or_payments_shows_the_full_balance(): void
    {
        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 50]],
        ], $this->admin);

        $html = $this->html($order);

        $this->assertStringNotContainsString('discount', strtolower($html));
        $this->assertStringNotContainsString('Amount paid', $html);
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('USD 50.00', $html);
    }

    public function test_the_pdf_still_renders(): void
    {
        $order = app(OrderService::class)->create([
            'customer_name' => 'Acme', 'status' => 'pending', 'order_date' => now()->toDateString(),
            'discount_type' => 'percent', 'discount_value' => 10,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 50]],
        ], $this->admin);
        app(OrderPaymentService::class)->record($order, $this->admin, ['amount' => 10, 'method' => 'cheque']);

        $this->actingAs($this->admin)->get(route('orders.invoice.download', $order))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
