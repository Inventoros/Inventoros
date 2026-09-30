<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\DocumentEmailException;
use App\Mail\PurchaseOrderEmail;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\SendPurchaseOrderTool;
use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\Setting;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\PurchaseOrderEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * "Send to supplier" must actually email the supplier the PO PDF, through one
 * service shared by the web UI, the REST API and the MCP tool.
 */
class PurchaseOrderEmailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $viewer;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create([
            'name' => 'Acme Hardware', 'email' => 'ops@acme.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        $adminRole = Role::create([
            'slug' => 'po-admin', 'name' => 'PO Admin', 'is_system' => false,
            'organization_id' => $this->org->id,
            'permissions' => ['view_purchase_orders', 'edit_purchase_orders'],
        ]);
        $viewerRole = Role::create([
            'slug' => 'po-viewer', 'name' => 'PO Viewer', 'is_system' => false,
            'organization_id' => $this->org->id,
            'permissions' => ['view_purchase_orders'],
        ]);

        $this->admin = User::create([
            'name' => 'Pat Buyer', 'email' => 'pat@acme.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $this->viewer = User::create([
            'name' => 'Vic Viewer', 'email' => 'vic@acme.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $this->viewer->roles()->syncWithoutDetaching([$viewerRole->id]);

        $this->supplier = Supplier::create([
            'organization_id' => $this->org->id, 'name' => 'Bolt Supply',
            'email' => 'orders@bolt.test', 'is_active' => true,
        ]);

        $this->product = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'B-1', 'name' => 'Bolt',
            'price' => 1, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0, 'is_active' => true,
        ]);
    }

    private function draftPo(array $attributes = [], ?Supplier $supplier = null): PurchaseOrder
    {
        $po = PurchaseOrder::create(array_merge([
            'organization_id' => $this->org->id,
            'supplier_id' => ($supplier ?? $this->supplier)->id,
            'created_by' => $this->admin->id,
            'po_number' => 'PO-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => now(),
            'subtotal' => 10, 'tax' => 0, 'shipping' => 0, 'total' => 10,
            'currency' => 'USD',
        ], $attributes));

        $po->items()->create([
            'product_id' => $this->product->id, 'product_name' => 'Bolt', 'sku' => 'B-1',
            'quantity_ordered' => 10, 'quantity_received' => 0,
            'unit_cost' => 1, 'subtotal' => 10, 'tax' => 0, 'total' => 10,
        ]);

        return $po;
    }

    // ==================== WEB ====================

    public function test_web_send_emails_the_supplier_and_marks_the_po_sent(): void
    {
        Mail::fake();
        $po = $this->draftPo();

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.send', $po), [
                'cc' => 'boss@acme.test, audit@acme.test',
                'message' => 'Please confirm by Friday.',
            ])
            ->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHas('success');

        Mail::assertQueued(PurchaseOrderEmail::class, function (PurchaseOrderEmail $mail) use ($po) {
            return $mail->hasTo('orders@bolt.test')
                && $mail->hasCc('boss@acme.test')
                && $mail->hasCc('audit@acme.test')
                && $mail->purchaseOrder->is($po)
                && $mail->customMessage === 'Please confirm by Friday.'
                && ($mail->data['organization_id'] ?? null) === $this->org->id;
        });

        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->status);
        $this->assertSame('orders@bolt.test', $po->sent_to);
        // Queued, not yet delivered: sent_at waits for the mail to go out.
        $this->assertNotNull($po->queued_at);
        $this->assertNull($po->sent_at);
        $this->assertTrue($po->emailIsQueued());
    }

    public function test_web_send_can_override_the_recipient(): void
    {
        Mail::fake();
        $po = $this->draftPo();

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.send', $po), ['to' => 'rep@bolt.test'])
            ->assertSessionHas('success');

        Mail::assertQueued(PurchaseOrderEmail::class, fn (PurchaseOrderEmail $m) => $m->hasTo('rep@bolt.test') && ! $m->hasTo('orders@bolt.test'));
        $this->assertSame('rep@bolt.test', $po->fresh()->sent_to);
    }

    public function test_web_send_rejects_an_invalid_cc(): void
    {
        Mail::fake();
        $po = $this->draftPo();

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.send', $po), ['cc' => 'not-an-email'])
            ->assertSessionHasErrors('cc');

        Mail::assertNothingQueued();
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
    }

    public function test_missing_supplier_email_fails_clearly_and_does_not_mark_sent(): void
    {
        Mail::fake();
        $noEmail = Supplier::create([
            'organization_id' => $this->org->id, 'name' => 'Quiet Supply', 'is_active' => true,
        ]);
        $po = $this->draftPo([], $noEmail);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.send', $po))
            ->assertRedirect(route('purchase-orders.show', $po))
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'Quiet Supply has no email address'));

        Mail::assertNothingQueued();
        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->status);
        $this->assertNull($po->sent_at);
    }

    public function test_status_is_not_changed_when_queueing_the_email_fails(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        $po = $this->draftPo();

        try {
            app(PurchaseOrderEmailService::class)->send($po, $this->admin);
            $this->fail('Expected the send to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SMTP down', $e->getMessage());
        }

        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->status);
        $this->assertNull($po->sent_at);
        $this->assertSame(0, ActivityLog::where('action', 'emailed')->count());
    }

    public function test_viewer_without_edit_permission_cannot_send(): void
    {
        Mail::fake();
        $po = $this->draftPo();

        $this->actingAs($this->viewer)
            ->post(route('purchase-orders.send', $po))
            ->assertForbidden();

        Mail::assertNothingQueued();
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
    }

    public function test_a_sent_po_can_be_resent_without_changing_status(): void
    {
        Mail::fake();
        $po = $this->draftPo(['status' => PurchaseOrder::STATUS_PARTIAL]);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.send', $po))
            ->assertSessionHas('success');

        Mail::assertQueued(PurchaseOrderEmail::class, 1);
        $po->refresh();
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $po->status);
        $this->assertNotNull($po->queued_at);
    }

    public function test_a_cancelled_po_cannot_be_sent(): void
    {
        Mail::fake();
        $po = $this->draftPo(['status' => PurchaseOrder::STATUS_CANCELLED]);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.send', $po))
            ->assertSessionHas('error');

        Mail::assertNothingQueued();
    }

    public function test_the_send_is_recorded_in_the_activity_log(): void
    {
        Mail::fake();
        $po = $this->draftPo();

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.send', $po), ['cc' => 'boss@acme.test']);

        $log = ActivityLog::where('subject_type', PurchaseOrder::class)
            ->where('subject_id', $po->id)
            ->where('action', 'emailed')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame($this->org->id, $log->organization_id);
        $this->assertSame('orders@bolt.test', $log->properties['to']);
        $this->assertSame(['boss@acme.test'], $log->properties['cc']);
        $this->assertNotEmpty($log->properties['sent_at']);
        $this->assertStringContainsString('orders@bolt.test', $log->description);
    }

    // ==================== MAILABLE ====================

    public function test_the_mailable_attaches_the_po_pdf(): void
    {
        $po = $this->draftPo();
        $mail = new PurchaseOrderEmail($po, 'Thanks!');
        $mail->build();

        $this->assertCount(1, $mail->rawAttachments);
        $this->assertSame($po->po_number.'.pdf', $mail->rawAttachments[0]['name']);
        $this->assertSame('application/pdf', $mail->rawAttachments[0]['options']['mime']);
        $this->assertStringStartsWith('%PDF', $mail->rawAttachments[0]['data']);
    }

    public function test_the_mailable_applies_the_org_mail_config(): void
    {
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.provider', 'value' => 'smtp', 'encrypted' => false]);
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.smtp.host', 'value' => 'smtp.acme.test', 'encrypted' => false]);
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.from_address', 'value' => 'buying@acme.test', 'encrypted' => false]);
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.from_name', 'value' => 'Acme Buying', 'encrypted' => false]);
        Config::set('mail.from.address', 'default@system.test');

        $po = $this->draftPo();
        $mail = new PurchaseOrderEmail($po, 'Please confirm by Friday.');
        $mail->build();

        // The org's From rides on its own mailer; the global config is untouched.
        $this->assertSame(['address' => 'buying@acme.test', 'name' => 'Acme Buying'], Config::get("mail.mailers.{$mail->mailer}.from"));
        $this->assertSame('default@system.test', Config::get('mail.from.address'));
    }

    public function test_the_mailable_is_branded_as_the_org_with_a_text_part(): void
    {
        // Rendering resolves the mailer, so give the org one that needs no host.
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.provider', 'value' => 'array', 'encrypted' => false]);
        $po = $this->draftPo();
        $mail = new PurchaseOrderEmail($po, 'Please confirm by Friday.');
        $mail->build();

        $this->assertStringContainsString($po->po_number, $mail->subject);
        $this->assertStringContainsString('Acme Hardware', $mail->subject);
        $this->assertSame('emails.text.purchase-order', $mail->textView);
        $this->assertTrue($mail->hasReplyTo('ops@acme.test'));

        $mail = new PurchaseOrderEmail($po, 'Please confirm by Friday.');
        $mail->assertSeeInHtml('Acme Hardware');
        $mail->assertDontSeeInHtml('email notifications enabled');
        $mail->assertSeeInHtml('Please confirm by Friday.');
        $mail->assertSeeInText('Acme Hardware');
        $mail->assertSeeInText($po->po_number);
        $mail->assertSeeInText('Please confirm by Friday.');
    }

    // ==================== REST API ====================

    public function test_api_send_emails_the_supplier(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin);
        $po = $this->draftPo();

        $this->postJson("/api/v1/purchase-orders/{$po->id}/send", ['cc' => ['boss@acme.test']])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.sent_to', 'orders@bolt.test');

        Mail::assertQueued(PurchaseOrderEmail::class, fn (PurchaseOrderEmail $m) => $m->hasTo('orders@bolt.test') && $m->hasCc('boss@acme.test'));
    }

    public function test_api_send_without_supplier_email_returns_422_and_does_not_mark_sent(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin);
        $noEmail = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Quiet Supply', 'is_active' => true]);
        $po = $this->draftPo([], $noEmail);

        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('error', 'missing_recipient');

        Mail::assertNothingQueued();
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
    }

    // ==================== THROTTLING ====================

    public function test_a_user_is_throttled_after_too_many_document_emails_in_a_minute(): void
    {
        Mail::fake();
        Config::set('limits.document_emails.per_user_per_minute', 2);
        Sanctum::actingAs($this->admin);
        $po = $this->draftPo();

        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")
            ->assertStatus(429)
            ->assertJsonPath('error', DocumentEmailException::RATE_LIMITED);

        Mail::assertQueued(PurchaseOrderEmail::class, 2);
        $this->assertSame(2, ActivityLog::where('subject_type', PurchaseOrder::class)->where('action', 'emailed')->count());

        // The web UI and MCP share the same budget.
        $this->actingAs($this->admin)->post(route('purchase-orders.send', $po))
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'Too many'));
        InventorosServer::actingAs($this->admin)
            ->tool(SendPurchaseOrderTool::class, ['id' => $po->id])
            ->assertHasErrors();
        Mail::assertQueued(PurchaseOrderEmail::class, 2);
    }

    public function test_an_organization_has_a_daily_cap_on_document_emails(): void
    {
        Mail::fake();
        Config::set('limits.document_emails.per_user_per_minute', 100);
        Config::set('limits.document_emails.per_organization_per_day', 2);
        $po = $this->draftPo();
        $colleague = User::create([
            'name' => 'Col', 'email' => 'col@acme.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $colleague->roles()->syncWithoutDetaching(Role::where('slug', 'po-admin')->pluck('id'));

        $service = app(PurchaseOrderEmailService::class);
        $service->send($po, $this->admin);
        $service->send($po, $colleague);

        try {
            $service->send($po, $colleague);
            $this->fail('The daily cap was not enforced.');
        } catch (DocumentEmailException $e) {
            $this->assertSame(DocumentEmailException::RATE_LIMITED, $e->reason);
        }

        Mail::assertQueued(PurchaseOrderEmail::class, 2);
    }

    // ==================== MCP ====================

    public function test_mcp_send_emails_the_supplier(): void
    {
        Mail::fake();
        $po = $this->draftPo();

        InventorosServer::actingAs($this->admin)
            ->tool(SendPurchaseOrderTool::class, ['id' => $po->id])
            ->assertOk()
            ->assertSee('orders@bolt.test');

        Mail::assertQueued(PurchaseOrderEmail::class, fn (PurchaseOrderEmail $m) => $m->hasTo('orders@bolt.test'));
        $this->assertSame(PurchaseOrder::STATUS_SENT, $po->fresh()->status);
    }

    public function test_mcp_send_without_supplier_email_errors(): void
    {
        Mail::fake();
        $noEmail = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Quiet Supply', 'is_active' => true]);
        $po = $this->draftPo([], $noEmail);

        InventorosServer::actingAs($this->admin)
            ->tool(SendPurchaseOrderTool::class, ['id' => $po->id])
            ->assertHasErrors(['no email address']);

        Mail::assertNothingQueued();
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->fresh()->status);
    }

    // ==================== ONE SERVICE ====================

    public function test_all_three_surfaces_route_through_the_one_service(): void
    {
        $po = $this->draftPo();

        $this->mock(PurchaseOrderEmailService::class, function (MockInterface $mock) use ($po) {
            $mock->shouldReceive('send')->times(3)->andReturn($po);
        });

        $this->actingAs($this->admin)->post(route('purchase-orders.send', $po))->assertSessionHas('success');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")->assertOk();

        InventorosServer::actingAs($this->admin)
            ->tool(SendPurchaseOrderTool::class, ['id' => $po->id])
            ->assertOk();
    }

    public function test_service_exception_names_the_supplier(): void
    {
        $noEmail = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Quiet Supply', 'is_active' => true]);
        $po = $this->draftPo([], $noEmail);

        $this->expectException(DocumentEmailException::class);
        $this->expectExceptionMessage('Quiet Supply has no email address');

        app(PurchaseOrderEmailService::class)->send($po, $this->admin);
    }
    public function test_sent_at_is_stamped_only_when_the_email_is_delivered(): void
    {
        // No Mail::fake: the array mailer really "delivers" the message, and
        // the sync queue runs the job inline, so MessageSent fires.
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.provider', 'value' => 'array', 'encrypted' => false]);
        $po = $this->draftPo();

        app(PurchaseOrderEmailService::class)->send($po, $this->admin);

        $po->refresh();
        $this->assertNotNull($po->queued_at);
        $this->assertNotNull($po->sent_at);
        $this->assertTrue($po->sent_at->greaterThanOrEqualTo($po->queued_at));
        $this->assertFalse($po->emailIsQueued());
    }

    public function test_a_resend_waiting_in_the_queue_reads_as_queued(): void
    {
        $po = $this->draftPo(['status' => PurchaseOrder::STATUS_SENT]);
        $po->forceFill(['sent_at' => now()->subDay(), 'queued_at' => now()->subDay()])->save();
        $this->assertFalse($po->fresh()->emailIsQueued());

        $po->forceFill(['queued_at' => now()])->save();
        $this->assertTrue($po->fresh()->emailIsQueued());
    }
}
