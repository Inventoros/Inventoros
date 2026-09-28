<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Mail\OrderInvoiceEmail;
use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\EmailOrderInvoiceTool;
use App\Models\Auth\Organization;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\OrderInvoiceEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Emailing an order invoice from the REST API and the MCP server goes through
 * the same OrderInvoiceEmailService as the web action, behind edit_orders.
 */
class OrderInvoiceEmailApiTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->editor = $this->makeMember($this->org, ['view_orders', 'edit_orders']);
    }

    private function order(array $attributes = [], ?Organization $org = null): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'organization_id' => ($org ?? $this->org)->id,
            'order_number' => 'ORD-'.uniqid(),
            'source' => 'manual',
            'customer_name' => 'Casey Customer',
            'customer_email' => 'casey@customer.test',
            'status' => 'pending',
            'subtotal' => 20, 'tax' => 0, 'shipping' => 0, 'total' => 20,
            'currency' => 'USD',
            'order_date' => now(),
        ], $attributes));
    }

    public function test_rest_emails_the_invoice_and_stamps_the_order(): void
    {
        Mail::fake();
        $order = $this->order();
        Sanctum::actingAs($this->editor);

        $this->postJson("/api/v1/orders/{$order->id}/invoice/email", ['cc' => ['books@acme.test'], 'message' => 'Thanks!'])
            ->assertOk()
            ->assertJsonPath('data.invoice_sent_to', 'casey@customer.test')
            ->assertJsonPath('data.invoice_queued_at', fn ($v) => $v !== null)
            ->assertJsonPath('data.invoice_number', $order->fresh()->invoice_number);

        Mail::assertQueued(OrderInvoiceEmail::class, fn (OrderInvoiceEmail $m) => $m->hasTo('casey@customer.test') && $m->hasCc('books@acme.test'));
        $this->assertNotNull($order->fresh()->invoice_queued_at);
    }

    public function test_rest_without_a_recipient_is_a_422(): void
    {
        Mail::fake();
        $order = $this->order(['customer_email' => null]);
        Sanctum::actingAs($this->editor);

        $this->postJson("/api/v1/orders/{$order->id}/invoice/email")
            ->assertStatus(422)
            ->assertJsonPath('error', 'missing_recipient');

        Mail::assertNothingQueued();
    }

    public function test_rest_requires_edit_orders_and_scopes_to_tenant(): void
    {
        Mail::fake();
        $order = $this->order();

        Sanctum::actingAs($this->makeMember($this->org, ['view_orders']));
        $this->postJson("/api/v1/orders/{$order->id}/invoice/email")->assertForbidden();

        $foreign = $this->order([], $this->makeOrganization('Other'));
        Sanctum::actingAs($this->editor);
        $this->postJson("/api/v1/orders/{$foreign->id}/invoice/email")->assertNotFound();

        Mail::assertNothingQueued();
    }

    public function test_rest_routes_through_the_service(): void
    {
        $order = $this->order();
        $this->mock(OrderInvoiceEmailService::class, function (MockInterface $mock) use ($order) {
            $mock->shouldReceive('send')->once()->andReturn($order);
        });

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/v1/orders/{$order->id}/invoice/email")->assertOk();
    }

    public function test_mcp_tool_emails_the_invoice(): void
    {
        Mail::fake();
        $order = $this->order();

        InventorosServer::actingAs($this->editor)
            ->tool(EmailOrderInvoiceTool::class, ['id' => $order->id, 'to' => 'ap@customer.test'])
            ->assertOk()
            ->assertSee('ap@customer.test');

        Mail::assertQueued(OrderInvoiceEmail::class, fn (OrderInvoiceEmail $m) => $m->hasTo('ap@customer.test'));
    }

    public function test_mcp_tool_requires_edit_orders(): void
    {
        Mail::fake();
        $order = $this->order();

        InventorosServer::actingAs($this->makeMember($this->org, ['view_orders']))
            ->tool(EmailOrderInvoiceTool::class, ['id' => $order->id])
            ->assertHasErrors();

        Mail::assertNothingQueued();
    }

    public function test_mcp_tool_scopes_to_tenant(): void
    {
        Mail::fake();

        $foreign = $this->order([], $this->makeOrganization('Other'));
        InventorosServer::actingAs($this->editor)
            ->tool(EmailOrderInvoiceTool::class, ['id' => $foreign->id])
            ->assertHasErrors(['not found']);

        Mail::assertNothingQueued();
    }
}
