<?php

declare(strict_types=1);

namespace Tests\Feature\ExchangeRates;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\CreatePurchaseOrderTool;
use App\Mcp\Tools\GetPurchaseOrderTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use App\Services\ReportDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The rate snapshot on every surface that returns purchase orders (REST,
 * GraphQL, MCP, the report builder), additively, and the optional rate
 * input with its permission (role and token abilities).
 */
final class PurchaseOrderExchangeRateApiTest extends TestCase
{
    use ExchangeRateFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->atTime();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $this->fakeFrankfurter(364.35);

        $this->org = $this->organization();
        $this->admin = $this->user($this->org);
        $this->supplier = $this->supplier($this->org, 'EUR');
        $this->product = $this->product($this->org);
    }

    protected function tearDown(): void
    {
        $this->tearDownExchangeRates();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'supplier_id' => $this->supplier->id,
            'order_date' => '2026-11-16',
            'currency' => 'EUR',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 50]],
        ];
    }

    public function test_rest_returns_the_snapshot_additively(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $id = $this->postJson('/api/v1/purchase-orders', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.total', '100.00')
            ->assertJsonPath('data.organization_currency', 'HUF')
            ->assertJsonPath('data.exchange_rate', '364.35')
            ->assertJsonPath('data.exchange_rate_from', 'EUR')
            ->assertJsonPath('data.exchange_rate_to', 'HUF')
            ->assertJsonPath('data.exchange_rate_provider', 'frankfurter')
            ->assertJsonPath('data.exchange_rate_effective_date', '2026-11-16')
            ->assertJsonPath('data.exchange_rate_source', 'provider')
            ->assertJsonPath('data.exchange_rate_locked_at', null)
            ->assertJsonPath('data.converted_total', '36435.00')
            ->assertJsonPath('data.organization_currency_total', '36435.00')
            ->json('data.id');

        $this->getJson("/api/v1/purchase-orders/{$id}")->assertOk()->assertJsonPath('data.converted_total', '36435.00');
    }

    public function test_rest_rate_override_needs_the_permission_in_the_role_and_the_token(): void
    {
        // A real token that may create POs but not override rates (the
        // admin's role could).
        $scoped = $this->admin->createToken('buyer', ['view_purchase_orders', 'create_purchase_orders'])->plainTextToken;
        $this->withToken($scoped)
            ->postJson('/api/v1/purchase-orders', $this->payload(['exchange_rate_mode' => 'manual', 'exchange_rate' => '400']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('exchange_rate');
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_rest_rate_override_with_a_token_that_allows_it(): void
    {
        $allowed = $this->admin->createToken('rates', ['view_purchase_orders', 'create_purchase_orders', 'override_purchase_order_exchange_rate'])->plainTextToken;
        $this->withToken($allowed)
            ->postJson('/api/v1/purchase-orders', $this->payload(['exchange_rate' => '400.5']))
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate', '400.5')
            ->assertJsonPath('data.exchange_rate_source', 'manual')
            ->assertJsonPath('data.exchange_rate_set_by', $this->admin->id);
    }

    public function test_rest_update_keeps_or_changes_the_rate(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $id = $this->postJson('/api/v1/purchase-orders', $this->payload())->json('data.id');

        $this->putJson("/api/v1/purchase-orders/{$id}", ['notes' => 'Thanks'])
            ->assertOk()
            ->assertJsonPath('data.exchange_rate', '364.35');

        $this->putJson("/api/v1/purchase-orders/{$id}", ['exchange_rate_mode' => 'manual', 'exchange_rate' => '366'])
            ->assertOk()
            ->assertJsonPath('data.exchange_rate', '366')
            ->assertJsonPath('data.converted_total', '36600.00');
    }

    public function test_graphql_exposes_the_snapshot_and_accepts_a_rate(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson('/graphql', [
            'query' => 'mutation($items: [PurchaseOrderItemInput!]!) { createPurchaseOrder(supplier_id: '.$this->supplier->id.', order_date: "2026-11-16", currency: "EUR", exchange_rate_mode: "manual", exchange_rate: "370.25", items: $items) { id currency total organization_currency exchange_rate exchange_rate_from exchange_rate_to exchange_rate_provider exchange_rate_source exchange_rate_effective_date exchange_rate_locked_at converted_total organization_currency_total } }',
            'variables' => ['items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 50]]],
        ])->assertOk();

        $this->assertNull($response->json('errors'));
        $po = $response->json('data.createPurchaseOrder');
        $this->assertSame('370.25', $po['exchange_rate']);
        $this->assertSame('manual', $po['exchange_rate_source']);
        $this->assertNull($po['exchange_rate_provider']);
        $this->assertSame('HUF', $po['organization_currency']);
        $this->assertEquals(37025.0, $po['converted_total']);
        $this->assertEquals(37025.0, $po['organization_currency_total']);

        $read = $this->postJson('/graphql', ['query' => '{ purchaseOrder(id: '.$po['id'].') { exchange_rate converted_total } }'])->assertOk();
        $this->assertSame('370.25', $read->json('data.purchaseOrder.exchange_rate'));
    }

    public function test_graphql_override_without_permission_is_a_validation_error(): void
    {
        $buyer = $this->user($this->org, 'member', ['view_purchase_orders', 'create_purchase_orders']);
        Sanctum::actingAs($buyer, ['*']);

        $response = $this->postJson('/graphql', [
            'query' => 'mutation($items: [PurchaseOrderItemInput!]!) { createPurchaseOrder(supplier_id: '.$this->supplier->id.', order_date: "2026-11-16", currency: "EUR", exchange_rate: "370", items: $items) { id } }',
            'variables' => ['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 1]]],
        ]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_mcp_tools_return_and_accept_the_rate(): void
    {
        InventorosServer::actingAs($this->admin)
            ->tool(CreatePurchaseOrderTool::class, $this->payload(['exchange_rate' => '365.5']))
            ->assertOk()
            ->assertSee('365.5');

        $po = PurchaseOrder::firstOrFail();
        $this->assertSame('365.5000000000', $po->exchange_rate);
        $this->assertSame('36550.00', $po->converted_total);

        InventorosServer::actingAs($this->admin)
            ->tool(GetPurchaseOrderTool::class, ['id' => $po->id])
            ->assertOk()
            ->assertSee(['organization_currency', 'HUF', 'recorded', '36550.00']);

        // Without a rate, the provider's is used.
        InventorosServer::actingAs($this->admin)->tool(CreatePurchaseOrderTool::class, $this->payload())->assertOk();
        $this->assertSame('364.3500000000', PurchaseOrder::latest('id')->first()->exchange_rate);
    }

    public function test_the_report_builder_offers_the_organization_currency_total(): void
    {
        $hufSupplier = $this->supplier($this->org, 'HUF');
        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson('/api/v1/purchase-orders', $this->payload())->assertCreated();
        $this->postJson('/api/v1/purchase-orders', $this->payload(['supplier_id' => $hufSupplier->id, 'currency' => 'HUF']))->assertCreated();
        $this->legacyPurchaseOrder($this->org, $this->supplier, $this->product, 'EUR');

        $rows = app(ReportDataService::class)->executeReport(
            $this->admin, $this->org->id, 'purchase_orders',
            ['po_number', 'currency', 'total', 'exchange_rate', 'converted_total'],
            null, ['field' => 'converted_total', 'direction' => 'desc'],
        );

        $byCurrency = collect($rows)->map(fn ($row) => (array) $row);
        $eur = $byCurrency->first(fn ($row) => $row['currency'] === 'EUR' && $row['exchange_rate'] !== null);
        $this->assertEquals(36435, (float) $eur['converted_total']);
        $huf = $byCurrency->firstWhere('currency', 'HUF');
        $this->assertEquals(100, (float) $huf['converted_total']);
        $legacy = $byCurrency->first(fn ($row) => $row['currency'] === 'EUR' && $row['exchange_rate'] === null);
        $this->assertNull($legacy['converted_total'], 'A foreign PO without a rate never mixes currencies into the total.');

        $filtered = app(ReportDataService::class)->executeReport(
            $this->admin, $this->org->id, 'purchase_orders', ['po_number', 'converted_total'],
            [['field' => 'converted_total', 'operator' => 'gt', 'value' => 1000]],
        );
        $this->assertCount(1, $filtered);
    }
}
