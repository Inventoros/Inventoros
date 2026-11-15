<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Mcp\Servers\InventorosServer;
use App\Mcp\Tools\ReceivePurchaseOrderTool;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\SavedReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * Collections a client sends or requests must be bounded: purchase-order
 * line arrays are capped at 500 (as on orders, transfers and returns) and
 * the saved-report list is paginated like every other REST list.
 */
class UnboundedApiCollectionsTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
        $this->supplier = Supplier::create([
            'organization_id' => $this->org->id, 'name' => 'Bolt Supply', 'email' => 'orders@bolt.test', 'is_active' => true,
        ]);
        $this->product = $this->makeProduct($this->org);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(int $count): array
    {
        return array_fill(0, $count, ['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 1]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function poPayload(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'currency' => 'USD',
        ], $overrides);
    }

    private function draftPo(string $status = PurchaseOrder::STATUS_DRAFT): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'organization_id' => $this->org->id,
            'supplier_id' => $this->supplier->id,
            'created_by' => $this->admin->id,
            'po_number' => 'PO-'.uniqid(),
            'status' => $status,
            'order_date' => now(),
            'subtotal' => 10, 'tax' => 0, 'shipping' => 0, 'total' => 10,
            'currency' => 'USD',
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'product_name' => $this->product->name, 'sku' => $this->product->sku,
            'quantity_ordered' => 10, 'quantity_received' => 0,
            'unit_cost' => 1, 'subtotal' => 10, 'tax' => 0, 'total' => 10,
        ]);

        return $po;
    }

    // ==================== PURCHASE ORDER LINES ====================

    public function test_rest_purchase_order_create_rejects_more_than_500_lines(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/purchase-orders', $this->poPayload(['notes' => 'bulk', 'items' => $this->lines(501)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $this->assertDatabaseMissing('purchase_orders', ['notes' => 'bulk']);
    }

    public function test_rest_purchase_order_create_accepts_500_lines(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/purchase-orders', $this->poPayload(['notes' => 'large', 'items' => $this->lines(500)]))
            ->assertCreated();

        $this->assertDatabaseHas('purchase_orders', ['notes' => 'large']);
    }

    public function test_rest_purchase_order_update_rejects_more_than_500_lines(): void
    {
        Sanctum::actingAs($this->admin);
        $po = $this->draftPo();

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['items' => $this->lines(501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $this->assertSame(1, $po->items()->count());
    }

    public function test_rest_purchase_order_update_still_replaces_lines(): void
    {
        Sanctum::actingAs($this->admin);
        $po = $this->draftPo();

        $this->putJson("/api/v1/purchase-orders/{$po->id}", ['items' => $this->lines(2)])
            ->assertOk();

        $this->assertSame(2, $po->items()->count());
    }

    public function test_rest_purchase_order_receive_rejects_more_than_500_lines(): void
    {
        Sanctum::actingAs($this->admin);
        $po = $this->draftPo(PurchaseOrder::STATUS_SENT);
        $itemId = PurchaseOrderItem::where('purchase_order_id', $po->id)->value('id');

        $this->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
            'items' => array_fill(0, 501, ['id' => $itemId, 'quantity_to_receive' => 0]),
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);
    }

    public function test_mcp_purchase_order_receive_rejects_more_than_500_lines(): void
    {
        $po = $this->draftPo(PurchaseOrder::STATUS_SENT);
        $itemId = PurchaseOrderItem::where('purchase_order_id', $po->id)->value('id');

        InventorosServer::actingAs($this->admin)
            ->tool(ReceivePurchaseOrderTool::class, [
                'id' => $po->id,
                'items' => array_fill(0, 501, ['id' => $itemId, 'quantity_to_receive' => 0]),
            ])
            ->assertHasErrors(['500']);

        $this->assertSame(0, (int) PurchaseOrderItem::whereKey($itemId)->value('quantity_received'));
    }

    public function test_graphql_purchase_order_mutations_reject_more_than_500_lines(): void
    {
        Sanctum::actingAs($this->admin);
        $items = implode(', ', array_fill(0, 501, "{ product_id: {$this->product->id}, quantity: 1, unit_cost: 1 }"));
        $po = $this->draftPo();

        $create = $this->postJson('/graphql', ['query' => "mutation { createPurchaseOrder(supplier_id: {$this->supplier->id}, order_date: \"".now()->toDateString()."\", currency: \"USD\", items: [{$items}]) { id } }"]);
        $update = $this->postJson('/graphql', ['query' => "mutation { updatePurchaseOrder(id: {$po->id}, items: [{$items}]) { id } }"]);

        foreach ([$create, $update] as $response) {
            $this->assertSame('validation', $response->json('errors.0.message'), (string) $response->getContent());
            $this->assertNotEmpty($response->json('errors.0.extensions.validation.items'));
        }
        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(1, $po->items()->count());
    }

    // ==================== SAVED REPORT LIST ====================

    private function report(User $owner, string $name, bool $shared = false, ?Organization $org = null): SavedReport
    {
        return SavedReport::create([
            'organization_id' => ($org ?? $this->org)->id,
            'created_by' => $owner->id,
            'name' => $name,
            'data_source' => 'products',
            'columns' => ['name', 'sku'],
            'is_shared' => $shared,
        ]);
    }

    public function test_saved_report_list_is_paginated_with_the_standard_envelope(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $this->report($this->admin, "Report {$name}");
        }
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/reports?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'data_source', 'is_shared', 'is_owner']], 'links', 'meta'])
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/reports?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_saved_report_list_clamps_the_page_size(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $this->report($this->admin, "Report {$name}");
        }
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('meta.per_page', 15)->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/reports?per_page=-1')->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/reports?per_page=100000')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_saved_report_list_still_shows_only_accessible_reports(): void
    {
        $colleague = $this->makeMember($this->org, ['view_reports']);
        $this->report($this->admin, 'Mine');
        $this->report($colleague, 'Shared by colleague', shared: true);
        $this->report($colleague, 'Private to colleague');
        $foreignOrg = $this->makeOrganization('Other');
        $this->report($this->makeAdmin($foreignOrg), 'Foreign shared', shared: true, org: $foreignOrg);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/reports')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertSee('Mine')
            ->assertSee('Shared by colleague')
            ->assertDontSee('Private to colleague')
            ->assertDontSee('Foreign shared');
    }
}
