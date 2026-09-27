<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * GraphQL parity: customers, returns, transfers, users (read-only), variants
 * and purchase-order writes, permission-gated like REST (role AND token
 * abilities), tenant-scoped, eager-loaded, and rate limited.
 */
final class GraphQLParityTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->markInstalled();

        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
        $this->product = $this->makeProduct($this->org, ['stock' => 40]);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function gql(string $query, array $variables = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/graphql', ['query' => $query, 'variables' => $variables]);
    }

    private function customer(Organization $org, string $name): Customer
    {
        return Customer::withoutGlobalScopes()->create(['organization_id' => $org->id, 'name' => $name, 'is_active' => true]);
    }

    private function deliveredOrder(User $creator, Product $product): Order
    {
        $this->actingAs($creator);
        $order = app(OrderService::class)->create([
            'customer_name' => 'Buyer', 'status' => 'delivered', 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 10]],
        ], $creator);
        auth()->forgetGuards();

        return $order->load('items');
    }

    // ==================== CUSTOMERS ====================

    public function test_customers_query_is_scoped_and_permission_gated(): void
    {
        $this->customer($this->org, 'Mine');
        $this->customer($this->makeOrganization('Other'), 'Theirs');

        Sanctum::actingAs($this->admin);
        $this->gql('{ customers { id name } }')
            ->assertJsonCount(1, 'data.customers')
            ->assertJsonPath('data.customers.0.name', 'Mine');

        Sanctum::actingAs($this->makeMember($this->org, ['view_products']));
        $response = $this->gql('{ customers { id name } }');
        $response->assertJsonPath('data.customers', null);
        $this->assertStringNotContainsString('Mine', $response->getContent());
    }

    public function test_customer_mutations(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->gql('mutation { createCustomer(name: "Gql Co", email: "g@co.test") { id name } }')
            ->assertJsonPath('data.createCustomer.name', 'Gql Co')
            ->json('data.createCustomer.id');

        $this->gql("mutation { updateCustomer(id: {$id}, name: \"Gql Inc\") { name } }")
            ->assertJsonPath('data.updateCustomer.name', 'Gql Inc');

        $foreign = $this->customer($this->makeOrganization('Other'), 'Theirs');
        $response = $this->gql("mutation { updateCustomer(id: {$foreign->id}, name: \"Hacked\") { name } }");
        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame('Theirs', Customer::withoutGlobalScopes()->find($foreign->id)->name);
    }

    public function test_a_scoped_token_cannot_run_a_mutation_its_role_allows(): void
    {
        // The admin role grants create_customers; the token only view_customers.
        $token = $this->admin->createToken('ro', ['view_customers'])->plainTextToken;

        $this->withToken($token)->postJson('/graphql', ['query' => '{ customers { id } }'])
            ->assertJsonMissingPath('errors');

        $response = $this->withToken($token)->postJson('/graphql', ['query' => 'mutation { createCustomer(name: "Nope") { id } }']);
        $this->assertNotEmpty($response->json('errors'));
        $this->assertDatabaseMissing('customers', ['name' => 'Nope']);
    }

    // ==================== RETURNS ====================

    public function test_return_lifecycle_through_graphql(): void
    {
        $order = $this->deliveredOrder($this->admin, $this->product);
        $stockAfterSale = $this->product->fresh()->stock;
        Sanctum::actingAs($this->admin);

        $itemId = $order->items->first()->id;
        $id = $this->gql(
            'mutation($items: [ReturnOrderItemInput!]!) { createReturnOrder(order_id: '.$order->id.', type: "return", reason: "Broken", items: $items) { id status items { product_id quantity } } }',
            ['items' => [['order_item_id' => $itemId, 'quantity' => 2, 'condition' => 'new', 'restock' => true]]]
        )->assertJsonPath('data.createReturnOrder.status', 'pending')
            ->assertJsonPath('data.createReturnOrder.items.0.product_id', $this->product->id)
            ->json('data.createReturnOrder.id');

        $this->gql("mutation { approveReturnOrder(id: {$id}) { status } }")->assertJsonPath('data.approveReturnOrder.status', 'approved');
        $this->gql("mutation { receiveReturnOrder(id: {$id}) { status } }")->assertJsonPath('data.receiveReturnOrder.status', 'received');
        $this->assertSame($stockAfterSale + 2, $this->product->fresh()->stock);

        $this->gql('{ returnOrders(status: "received") { id return_number order { id } } }')
            ->assertJsonCount(1, 'data.returnOrders')
            ->assertJsonPath('data.returnOrders.0.order.id', $order->id);
        $this->gql("{ returnOrder(id: {$id}) { id status } }")->assertJsonPath('data.returnOrder.status', 'received');
    }

    public function test_returns_require_manage_returns_and_are_scoped(): void
    {
        $other = $this->makeOrganization('Other');
        $otherAdmin = $this->makeAdmin($other);
        $foreignOrder = $this->deliveredOrder($otherAdmin, $this->makeProduct($other));
        $foreignReturn = ReturnOrder::create([
            'organization_id' => $other->id, 'order_id' => $foreignOrder->id, 'return_number' => 'RMA-X',
            'type' => 'return', 'status' => 'pending', 'reason' => 'x', 'refund_amount' => 0,
        ]);

        Sanctum::actingAs($this->admin);
        $this->assertNotEmpty($this->gql("{ returnOrder(id: {$foreignReturn->id}) { id } }")->json('errors'));
        $this->assertNotEmpty($this->gql("mutation { approveReturnOrder(id: {$foreignReturn->id}) { id } }")->json('errors'));
        $this->assertSame('pending', $foreignReturn->fresh()->status);

        Sanctum::actingAs($this->makeMember($this->org, ['view_orders']));
        $this->gql('{ returnOrders { id } }')->assertJsonPath('data.returnOrders', null);
    }

    // ==================== TRANSFERS ====================

    public function test_transfer_create_and_complete_through_graphql(): void
    {
        $from = $this->makeLocation($this->org, 'Front');
        $to = $this->makeLocation($this->org, 'Back');
        $this->product->update(['location_id' => $from->id]);
        Sanctum::actingAs($this->admin);

        $id = $this->gql(
            'mutation($items: [StockTransferItemInput!]!) { createStockTransfer(from_location_id: '.$from->id.', to_location_id: '.$to->id.', items: $items) { id status } }',
            ['items' => [['product_id' => $this->product->id, 'quantity' => 15]]]
        )->assertJsonPath('data.createStockTransfer.status', 'pending')->json('data.createStockTransfer.id');

        $this->gql("mutation { completeStockTransfer(id: {$id}) { status to_location { id } items { quantity product { id } } } }")
            ->assertJsonPath('data.completeStockTransfer.status', 'completed')
            ->assertJsonPath('data.completeStockTransfer.to_location.id', $to->id);

        $this->assertSame($to->id, $this->product->fresh()->location_id);

        $this->gql('{ stockTransfers { id transfer_number from_location { name } } }')
            ->assertJsonCount(1, 'data.stockTransfers')
            ->assertJsonPath('data.stockTransfers.0.from_location.name', 'Front');
    }

    public function test_transfer_rejects_foreign_location(): void
    {
        $from = $this->makeLocation($this->org, 'Front');
        $foreign = $this->makeLocation($this->makeOrganization('Other'), 'Foreign');
        Sanctum::actingAs($this->admin);

        $response = $this->gql(
            'mutation($items: [StockTransferItemInput!]!) { createStockTransfer(from_location_id: '.$from->id.', to_location_id: '.$foreign->id.', items: $items) { id } }',
            ['items' => [['product_id' => $this->product->id, 'quantity' => 1]]]
        );

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(0, StockTransfer::count());
    }

    // ==================== USERS / VARIANTS ====================

    public function test_users_query_is_read_only_scoped_and_gated(): void
    {
        $this->makeAdmin($this->makeOrganization('Other'));
        Sanctum::actingAs($this->admin);

        $response = $this->gql('{ users { id email role roles { slug } } }')
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.email', $this->admin->email);
        $this->assertStringNotContainsString('password', $response->getContent());

        $this->gql("{ user(id: {$this->admin->id}) { name } }")->assertJsonPath('data.user.name', $this->admin->name);

        Sanctum::actingAs($this->makeMember($this->org, ['view_products']));
        $this->gql('{ users { id } }')->assertJsonPath('data.users', null);
    }

    public function test_product_variants_query(): void
    {
        ProductVariant::create([
            'organization_id' => $this->org->id, 'product_id' => $this->product->id,
            'sku' => 'VAR-RED', 'title' => 'Red', 'option_values' => ['Color' => 'Red'], 'stock' => 3, 'is_active' => true,
        ]);
        $otherProduct = $this->makeProduct($otherOrg = $this->makeOrganization('Other'));
        ProductVariant::create([
            'organization_id' => $otherOrg->id, 'product_id' => $otherProduct->id,
            'sku' => 'VAR-X', 'title' => 'Foreign', 'option_values' => ['Color' => 'Blue'], 'stock' => 1, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->gql("{ productVariants(product_id: {$this->product->id}) { sku title product { id } } }")
            ->assertJsonCount(1, 'data.productVariants')
            ->assertJsonPath('data.productVariants.0.sku', 'VAR-RED');

        $this->gql('{ productVariants { sku } }')->assertJsonCount(1, 'data.productVariants');
    }

    // ==================== PURCHASE ORDERS ====================

    public function test_purchase_order_create_update_and_receive(): void
    {
        $supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Bolt', 'email' => 'po@bolt.test', 'is_active' => true]);
        Sanctum::actingAs($this->admin);

        $po = $this->gql(
            'mutation($items: [PurchaseOrderItemInput!]!) { createPurchaseOrder(supplier_id: '.$supplier->id.', order_date: "2026-09-01", currency: "USD", items: $items) { id status total items { id quantity_ordered } } }',
            ['items' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 2.5]]]
        )->assertJsonPath('data.createPurchaseOrder.status', 'draft')->json('data.createPurchaseOrder');
        $this->assertEquals(12.5, $po['total']);

        $this->gql("mutation { updatePurchaseOrder(id: {$po['id']}, notes: \"Rush\", shipping: 2.5) { notes total } }")
            ->assertJsonPath('data.updatePurchaseOrder.notes', 'Rush');

        PurchaseOrder::find($po['id'])->update(['status' => PurchaseOrder::STATUS_SENT]);

        $this->gql(
            'mutation($items: [PurchaseOrderReceiveItemInput!]!) { receivePurchaseOrder(id: '.$po['id'].', items: $items) { status sent_at sent_to } }',
            ['items' => [['id' => $po['items'][0]['id'], 'quantity_to_receive' => 5]]]
        )->assertJsonPath('data.receivePurchaseOrder.status', 'received');

        $this->assertSame(45, $this->product->fresh()->stock);
    }

    public function test_purchase_order_mutations_are_gated_and_scoped(): void
    {
        $foreignSupplier = Supplier::create(['organization_id' => $this->makeOrganization('Other')->id, 'name' => 'Foreign', 'is_active' => true]);
        Sanctum::actingAs($this->admin);

        $response = $this->gql(
            'mutation($items: [PurchaseOrderItemInput!]!) { createPurchaseOrder(supplier_id: '.$foreignSupplier->id.', order_date: "2026-09-01", currency: "USD", items: $items) { id } }',
            ['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 1]]]
        );
        $this->assertNotEmpty($response->json('errors'));

        $supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Bolt', 'is_active' => true]);
        Sanctum::actingAs($this->makeMember($this->org, ['view_purchase_orders']));
        $response = $this->gql(
            'mutation($items: [PurchaseOrderItemInput!]!) { createPurchaseOrder(supplier_id: '.$supplier->id.', order_date: "2026-09-01", currency: "USD", items: $items) { id } }',
            ['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 1]]]
        );
        // Authorization runs before argument validation, so the refusal is a
        // plain "Unauthorized" rather than a validation error.
        $this->assertSame('Unauthorized', $response->json('errors.0.message'));
        $this->assertSame(0, PurchaseOrder::withoutGlobalScopes()->count());
    }

    public function test_sent_and_invoice_fields_are_exposed(): void
    {
        $supplier = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Bolt', 'is_active' => true]);
        $po = PurchaseOrder::create([
            'organization_id' => $this->org->id, 'supplier_id' => $supplier->id, 'po_number' => 'PO-1',
            'status' => PurchaseOrder::STATUS_SENT, 'order_date' => now(), 'subtotal' => 0, 'tax' => 0, 'total' => 0,
            'currency' => 'USD', 'created_by' => $this->admin->id,
        ]);
        $po->forceFill(['sent_at' => now(), 'sent_to' => 'po@bolt.test'])->save();

        $order = $this->deliveredOrder($this->admin, $this->product);
        $order->forceFill(['invoice_number' => 'INV-000042', 'invoice_sent_at' => now()])->save();

        Sanctum::actingAs($this->admin);

        $this->gql("{ purchaseOrder(id: {$po->id}) { sent_to sent_at } }")
            ->assertJsonPath('data.purchaseOrder.sent_to', 'po@bolt.test');
        $this->assertNotNull($this->gql("{ purchaseOrder(id: {$po->id}) { sent_at } }")->json('data.purchaseOrder.sent_at'));

        $this->gql("{ order(id: {$order->id}) { invoice_number invoice_sent_at } }")
            ->assertJsonPath('data.order.invoice_number', 'INV-000042');
    }

    // ==================== N+1 / RATE LIMIT ====================

    public function test_list_queries_do_not_issue_a_query_per_row(): void
    {
        $from = $this->makeLocation($this->org, 'Front');
        $to = $this->makeLocation($this->org, 'Back');
        foreach (range(1, 6) as $i) {
            StockTransfer::create([
                'organization_id' => $this->org->id, 'transfer_number' => "TRF-{$i}",
                'from_location_id' => $from->id, 'to_location_id' => $to->id,
                'transferred_by' => $this->admin->id, 'status' => 'pending',
            ])->items()->create(['product_id' => $this->product->id, 'quantity' => 1]);
        }
        Sanctum::actingAs($this->admin);

        DB::enableQueryLog();
        $this->gql('{ stockTransfers { id from_location { name } to_location { name } transferred_by_name items { quantity product { name } } } }')
            ->assertJsonCount(6, 'data.stockTransfers');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Auth + permission lookups plus one query per eager-loaded relation;
        // a per-row N+1 over 6 transfers x 5 relations would be 30+.
        $this->assertLessThan(20, $queries, "stockTransfers issued {$queries} queries");
    }

    public function test_graphql_is_rate_limited_like_the_rest_api(): void
    {
        Sanctum::actingAs($this->admin);

        for ($i = 0; $i < 60; $i++) {
            $this->gql('{ customers { id } }')->assertOk();
        }

        $this->gql('{ customers { id } }')->assertStatus(429);
    }
}
