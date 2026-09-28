<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\OrderInvoiceEmail;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Documents\OrderInvoiceNumberService;
use App\Services\OrderInvoiceEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Order invoices get a per-organization invoice number the first time they
 * are generated, and can be emailed to the customer with the PDF attached.
 */
class OrderInvoiceEmailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private User $editor;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Acme Hardware', 'email' => 'billing@acme.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->otherOrg = Organization::create([
            'name' => 'Other Co', 'email' => 'x@other.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $editorRole = Role::create([
            'slug' => 'order-editor', 'name' => 'Order Editor', 'is_system' => false,
            'organization_id' => $this->org->id, 'permissions' => ['view_orders', 'edit_orders'],
        ]);
        $viewerRole = Role::create([
            'slug' => 'order-viewer', 'name' => 'Order Viewer', 'is_system' => false,
            'organization_id' => $this->org->id, 'permissions' => ['view_orders'],
        ]);

        $this->editor = User::create([
            'name' => 'Eve Editor', 'email' => 'eve@acme.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $this->editor->roles()->syncWithoutDetaching([$editorRole->id]);

        $this->viewer = User::create([
            'name' => 'Vic Viewer', 'email' => 'vic@acme.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $this->viewer->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    private function order(array $attributes = [], ?Organization $org = null): Order
    {
        $order = Order::create(array_merge([
            'organization_id' => ($org ?? $this->org)->id,
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'source' => 'manual',
            'customer_name' => 'Casey Customer',
            'customer_email' => 'casey@customer.test',
            'status' => 'pending',
            'subtotal' => 20, 'tax' => 0, 'shipping' => 0, 'total' => 20,
            'currency' => 'USD',
            'order_date' => now(),
        ], $attributes));

        $order->items()->create([
            'product_name' => 'Widget', 'sku' => 'W-1', 'quantity' => 2,
            'unit_price' => 10, 'subtotal' => 20, 'tax' => 0, 'total' => 20,
        ]);

        return $order;
    }

    // ==================== INVOICE NUMBERS ====================

    public function test_invoice_number_is_assigned_on_first_generation_and_kept(): void
    {
        $order = $this->order();
        $this->assertNull($order->invoice_number);

        $this->actingAs($this->viewer)
            ->get(route('orders.invoice.download', $order))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=INV-000001.pdf');

        $order->refresh();
        $this->assertSame('INV-000001', $order->invoice_number);
        $this->assertNotNull($order->invoice_issued_at);

        // Generating again (preview, another download) keeps the same number.
        $this->actingAs($this->viewer)->get(route('orders.invoice.preview', $order))->assertOk();
        $this->assertSame('INV-000001', $order->fresh()->invoice_number);
    }

    public function test_invoice_numbers_are_sequential_per_organization(): void
    {
        $service = app(OrderInvoiceNumberService::class);

        $a = $this->order();
        $b = $this->order();
        $foreign = $this->order([], $this->otherOrg);

        $this->assertSame('INV-000001', $service->ensureAssigned($a));
        $this->assertSame('INV-000002', $service->ensureAssigned($b));
        // Another tenant starts its own sequence.
        $this->assertSame('INV-000001', $service->ensureAssigned($foreign));
    }

    public function test_invoice_numbering_counts_soft_deleted_orders(): void
    {
        $service = app(OrderInvoiceNumberService::class);

        $trashed = $this->order(['invoice_number' => 'INV-000007']);
        $trashed->delete();

        $this->assertSame('INV-000008', $service->ensureAssigned($this->order()));
    }

    public function test_invoice_numbering_retries_on_a_collision(): void
    {
        $this->order(['invoice_number' => 'INV-000001']);
        $order = $this->order();

        // Simulate a concurrent writer: the first read hands out a number that
        // is already taken; the retry must read again and use the next one.
        $service = $this->partialMock(OrderInvoiceNumberService::class, function (MockInterface $mock) {
            $mock->shouldReceive('next')->twice()->andReturn('INV-000001', 'INV-000002');
        });

        $this->assertSame('INV-000002', $service->ensureAssigned($order));
        $this->assertSame('INV-000002', $order->fresh()->invoice_number);
    }

    public function test_an_order_already_numbered_elsewhere_keeps_that_number(): void
    {
        $order = $this->order();
        $stale = Order::find($order->id);

        // Another request numbers the order first; this (stale) copy must adopt
        // that number rather than allocating a second one.
        app(OrderInvoiceNumberService::class)->ensureAssigned($order);

        $this->assertSame('INV-000001', app(OrderInvoiceNumberService::class)->ensureAssigned($stale));
        $this->assertSame(1, Order::whereNotNull('invoice_number')->count());
    }

    // ==================== EMAILING ====================

    public function test_editor_can_email_the_invoice_to_the_customer(): void
    {
        Mail::fake();
        $order = $this->order();

        $this->actingAs($this->editor)
            ->post(route('orders.invoice.email', $order), [
                'cc' => 'accounts@acme.test',
                'message' => 'Thanks for your business.',
            ])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        Mail::assertQueued(OrderInvoiceEmail::class, function (OrderInvoiceEmail $mail) use ($order) {
            return $mail->hasTo('casey@customer.test')
                && $mail->hasCc('accounts@acme.test')
                && $mail->order->is($order)
                && $mail->customMessage === 'Thanks for your business.'
                && ($mail->data['organization_id'] ?? null) === $this->org->id;
        });

        $order->refresh();
        $this->assertSame('INV-000001', $order->invoice_number);
        $this->assertNotNull($order->invoice_sent_at);
        $this->assertSame('casey@customer.test', $order->invoice_sent_to);

        $log = ActivityLog::where('subject_type', Order::class)->where('subject_id', $order->id)
            ->where('action', 'invoice_emailed')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->editor->id, $log->user_id);
        $this->assertSame('casey@customer.test', $log->properties['to']);
    }

    public function test_emailing_without_a_customer_email_fails_and_records_nothing(): void
    {
        Mail::fake();
        $order = $this->order(['customer_email' => null]);

        $this->actingAs($this->editor)
            ->post(route('orders.invoice.email', $order))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'no customer email address'));

        Mail::assertNothingQueued();
        $this->assertNull($order->fresh()->invoice_sent_at);
    }

    public function test_viewer_cannot_email_the_invoice(): void
    {
        Mail::fake();
        $order = $this->order();

        $this->actingAs($this->viewer)
            ->post(route('orders.invoice.email', $order))
            ->assertForbidden();

        Mail::assertNothingQueued();
    }

    public function test_cannot_email_another_organizations_invoice(): void
    {
        Mail::fake();
        $foreign = $this->order([], $this->otherOrg);

        $this->actingAs($this->editor)
            ->post(route('orders.invoice.email', $foreign))
            ->assertNotFound();

        Mail::assertNothingQueued();
    }

    public function test_the_web_action_routes_through_the_service(): void
    {
        $order = $this->order();

        $this->mock(OrderInvoiceEmailService::class, function (MockInterface $mock) use ($order) {
            $mock->shouldReceive('send')->once()->andReturn($order);
        });

        $this->actingAs($this->editor)->post(route('orders.invoice.email', $order))->assertSessionHas('success');
    }

    // ==================== MAILABLE ====================

    public function test_the_invoice_mailable_attaches_the_pdf_and_applies_org_config(): void
    {
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.provider', 'value' => 'smtp', 'encrypted' => false]);
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.smtp.host', 'value' => 'smtp.acme.test', 'encrypted' => false]);
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.from_address', 'value' => 'invoices@acme.test', 'encrypted' => false]);
        Config::set('mail.from.address', 'default@system.test');

        $order = $this->order();
        $mail = new OrderInvoiceEmail($order, 'Thanks!');
        $mail->build();

        // The org's From rides on its own mailer; the global config is untouched.
        $this->assertSame('invoices@acme.test', Config::get("mail.mailers.{$mail->mailer}.from.address"));
        $this->assertSame('default@system.test', Config::get('mail.from.address'));
        $this->assertCount(1, $mail->rawAttachments);
        $this->assertSame('INV-000001.pdf', $mail->rawAttachments[0]['name']);
        $this->assertStringStartsWith('%PDF', $mail->rawAttachments[0]['data']);
        $this->assertStringContainsString('INV-000001', $mail->subject);
        $this->assertStringContainsString('Acme Hardware', $mail->subject);
        $this->assertSame('emails.text.order-invoice', $mail->textView);

        $mail = new OrderInvoiceEmail($order->fresh(), 'Thanks!');
        $mail->assertSeeInHtml('Acme Hardware');
        $mail->assertSeeInText('INV-000001');
        $mail->assertSeeInText('Thanks!');
        $mail->assertSeeInText('Acme Hardware');
    }
}
