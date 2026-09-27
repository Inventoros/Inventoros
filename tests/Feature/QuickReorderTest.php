<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Create PO" from the dashboard reorder suggestions / low-stock report:
 * one DRAFT purchase order per primary supplier for the selected products.
 */
final class QuickReorderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private User $admin;

    private Supplier $acme;

    private Supplier $globex;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->otherOrg = Organization::create(['name' => 'Other', 'email' => 'x@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->acme = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Acme', 'is_active' => true]);
        $this->globex = Supplier::create(['organization_id' => $this->org->id, 'name' => 'Globex', 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(string $sku, array $attributes = [], ?Supplier $primary = null, array $link = []): Product
    {
        $product = Product::create(array_merge([
            'organization_id' => $this->org->id, 'sku' => $sku, 'name' => "Product {$sku}",
            'price' => 20, 'purchase_price' => 9, 'currency' => 'USD',
            'stock' => 2, 'min_stock' => 5, 'reorder_point' => 5, 'reorder_quantity' => 40,
        ], $attributes));

        if ($primary) {
            $product->suppliers()->attach($primary->id, array_merge(['cost_price' => 6.5, 'is_primary' => true], $link));
        }

        return $product;
    }

    public function test_a_single_product_creates_a_draft_po_for_its_primary_supplier_and_opens_it(): void
    {
        $product = $this->product('A', [], $this->acme, ['supplier_sku' => 'AC-A']);

        $response = $this->actingAs($this->admin)
            ->post(route('purchase-orders.quick-reorder'), ['product_ids' => [$product->id]]);

        $po = PurchaseOrder::sole();
        $response->assertRedirect(route('purchase-orders.edit', $po));

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $po->status);
        $this->assertSame($this->acme->id, $po->supplier_id);
        $this->assertSame($this->org->id, $po->organization_id);
        $this->assertSame($this->admin->id, $po->created_by);

        $item = $po->items()->sole();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame(40, $item->quantity_ordered);
        $this->assertEquals(6.5, (float) $item->unit_cost);
        $this->assertSame('AC-A', $item->supplier_sku);
        $this->assertEquals(260.0, (float) $po->total);

        $this->assertTrue(ActivityLog::where('subject_id', $po->id)->where('action', 'quick_reorder')->exists());
    }

    public function test_multi_select_groups_products_into_one_draft_po_per_primary_supplier(): void
    {
        $a = $this->product('A', [], $this->acme);
        $b = $this->product('B', [], $this->acme);
        $c = $this->product('C', [], $this->globex);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.quick-reorder'), ['product_ids' => [$a->id, $b->id, $c->id]])
            ->assertRedirect(route('purchase-orders.index', ['status' => PurchaseOrder::STATUS_DRAFT]))
            ->assertSessionHas('success');

        $this->assertSame(2, PurchaseOrder::count());
        $acmePo = PurchaseOrder::where('supplier_id', $this->acme->id)->sole();
        $globexPo = PurchaseOrder::where('supplier_id', $this->globex->id)->sole();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $acmePo->items()->pluck('product_id')->all());
        $this->assertSame([$c->id], $globexPo->items()->pluck('product_id')->all());
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $acmePo->status);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $globexPo->status);
    }

    public function test_a_product_without_a_supplier_is_refused_with_a_clear_error(): void
    {
        $orphan = $this->product('ORPHAN');

        $this->actingAs($this->admin)
            ->from(route('dashboard'))
            ->post(route('purchase-orders.quick-reorder'), ['product_ids' => [$orphan->id]])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Product ORPHAN')
                && str_contains($message, 'no primary supplier'));

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_products_without_a_supplier_are_skipped_and_named_when_others_can_be_ordered(): void
    {
        $ok = $this->product('OK', [], $this->acme);
        $orphan = $this->product('ORPHAN');

        $response = $this->actingAs($this->admin)
            ->post(route('purchase-orders.quick-reorder'), ['product_ids' => [$ok->id, $orphan->id]]);

        $po = PurchaseOrder::sole();
        $response->assertRedirect(route('purchase-orders.edit', $po))
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, 'Product ORPHAN'));
        $this->assertSame([$ok->id], $po->items()->pluck('product_id')->all());
    }

    public function test_a_product_from_another_organization_is_rejected(): void
    {
        $foreignSupplier = Supplier::create(['organization_id' => $this->otherOrg->id, 'name' => 'Foreign', 'is_active' => true]);
        $foreign = Product::create([
            'organization_id' => $this->otherOrg->id, 'sku' => 'F-1', 'name' => 'Foreign product',
            'price' => 10, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 5, 'reorder_quantity' => 10,
        ]);
        $foreign->suppliers()->attach($foreignSupplier->id, ['cost_price' => 1, 'is_primary' => true]);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.quick-reorder'), ['product_ids' => [$foreign->id]])
            ->assertSessionHasErrors('product_ids.0');

        $this->assertSame(0, PurchaseOrder::withoutGlobalScopes()->count());
    }

    public function test_the_create_purchase_orders_permission_is_required(): void
    {
        Role::firstOrCreate(['slug' => 'system-member'], [
            'name' => 'Member', 'is_system' => true,
            'permissions' => ['view_products', 'view_purchase_orders'],
        ]);
        $member = User::create([
            'name' => 'Member', 'email' => 'm@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $product = $this->product('A', [], $this->acme);

        $this->actingAs($member)
            ->post(route('purchase-orders.quick-reorder'), ['product_ids' => [$product->id]])
            ->assertForbidden();

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_without_edit_permission_the_user_lands_on_the_po_page_instead_of_the_editor(): void
    {
        Role::firstOrCreate(['slug' => 'system-member'], [
            'name' => 'Member', 'is_system' => true,
            'permissions' => ['view_products', 'view_purchase_orders', 'create_purchase_orders'],
        ]);
        $member = User::create([
            'name' => 'Member', 'email' => 'm@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $product = $this->product('A', [], $this->acme);

        $response = $this->actingAs($member)
            ->post(route('purchase-orders.quick-reorder'), ['product_ids' => [$product->id]]);

        $response->assertRedirect(route('purchase-orders.show', PurchaseOrder::sole()));
    }

    public function test_suggested_quantity_falls_back_to_the_max_stock_gap_and_honours_the_minimum_order(): void
    {
        // No reorder quantity: order up to max_stock (30 - 2 = 28).
        $gap = $this->product('GAP', ['reorder_point' => null, 'reorder_quantity' => null, 'max_stock' => 30], $this->acme);
        // Reorder quantity below the supplier's minimum order: order the minimum.
        $moq = $this->product('MOQ', ['reorder_quantity' => 10], $this->globex, ['minimum_order_quantity' => 25]);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.quick-reorder'), ['product_ids' => [$gap->id, $moq->id]]);

        $this->assertSame(28, PurchaseOrder::where('supplier_id', $this->acme->id)->sole()->items()->sole()->quantity_ordered);
        $this->assertSame(25, PurchaseOrder::where('supplier_id', $this->globex->id)->sole()->items()->sole()->quantity_ordered);
    }

    public function test_dashboard_suggestions_carry_the_supplier_id_and_suggested_quantity(): void
    {
        $this->product('A', ['reorder_quantity' => 12], $this->acme);
        $this->product('ORPHAN');

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reorderSuggestions', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['sku'] === 'A' && $row['supplier_id'] === $this->acme->id && $row['suggested_quantity'] === 12
                ) && collect($rows)->contains(
                    fn ($row) => $row['sku'] === 'ORPHAN' && $row['supplier_id'] === null
                ))
            );
    }

    public function test_low_stock_report_rows_carry_the_primary_supplier_and_suggested_quantity(): void
    {
        $this->product('A', ['reorder_quantity' => 12], $this->acme);

        $this->actingAs($this->admin)->get(route('reports.low-stock'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.0.supplier', 'Acme')
                ->where('products.0.supplier_id', $this->acme->id)
                ->where('products.0.suggested_quantity', 12)
            );
    }
}
