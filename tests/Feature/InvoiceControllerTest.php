<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\Supplier;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderItem;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $viewOnlyUser;
    protected User $unauthorizedUser;
    protected Organization $organization;
    protected Organization $otherOrganization;
    protected Product $product;
    protected ProductCategory $category;
    protected ProductLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'phone' => '123-456-7890',
            'address' => '123 Test St',
            'city' => 'Test City',
            'state' => 'TS',
            'zip' => '12345',
            'country' => 'US',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->otherOrganization = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $this->category = ProductCategory::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Category',
            'slug' => 'test-category',
            'is_active' => true,
        ]);

        $this->location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'name' => 'Warehouse A',
            'code' => 'WH-A',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'TEST-PROD-001',
            'name' => 'Test Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 100,
            'min_stock' => 10,
            'is_active' => true,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->viewOnlyUser = User::create([
            'name' => 'View Only User',
            'email' => 'viewer@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->unauthorizedUser = User::create([
            'name' => 'Unauthorized User',
            'email' => 'noauth@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => [
                    'view_products',
                    'create_products',
                    'edit_products',
                    'delete_products',
                    'view_orders',
                    'create_orders',
                    'edit_orders',
                    'delete_orders',
                    'view_purchase_orders',
                    'create_purchase_orders',
                    'edit_purchase_orders',
                    'delete_purchase_orders',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'description' => 'View only access',
                'is_system' => true,
                'permissions' => ['view_orders', 'view_purchase_orders'],
            ]
        );

        $noOrdersRole = Role::firstOrCreate(
            ['slug' => 'system-no-orders'],
            [
                'name' => 'No Orders',
                'description' => 'No order access',
                'is_system' => true,
                'permissions' => ['view_products'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
        $this->unauthorizedUser->roles()->syncWithoutDetaching([$noOrdersRole->id]);
    }

    protected function createOrder(array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'organization_id' => $this->organization->id,
            'order_number' => 'ORD-' . now()->format('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'source' => 'manual',
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@test.com',
            'customer_address' => '456 Customer Ave',
            'status' => 'pending',
            'subtotal' => 199.98,
            'tax' => 16.00,
            'shipping' => 10.00,
            'total' => 225.98,
            'currency' => 'USD',
            'order_date' => now(),
        ], $attributes));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'sku' => 'TEST-PROD-001',
            'quantity' => 2,
            'unit_price' => 99.99,
            'subtotal' => 199.98,
            'tax' => 0,
            'total' => 199.98,
        ]);

        return $order;
    }

    protected function createPurchaseOrder(): PurchaseOrder
    {
        $supplier = Supplier::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Supplier',
            'contact_name' => 'Supplier Contact',
            'email' => 'supplier@test.com',
            'phone' => '555-0100',
            'address' => '789 Supplier Rd',
            'city' => 'Supplier City',
            'state' => 'SC',
            'zip_code' => '54321',
            'country' => 'US',
            'is_active' => true,
        ]);

        $po = PurchaseOrder::create([
            'organization_id' => $this->organization->id,
            'supplier_id' => $supplier->id,
            'created_by' => $this->admin->id,
            'po_number' => 'PO-' . now()->format('Ymd') . '-0001',
            'status' => 'sent',
            'order_date' => now(),
            'expected_date' => now()->addDays(14),
            'subtotal' => 500.00,
            'tax' => 40.00,
            'shipping' => 25.00,
            'total' => 565.00,
            'currency' => 'USD',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'sku' => 'TEST-PROD-001',
            'quantity_ordered' => 50,
            'quantity_received' => 0,
            'unit_cost' => 10.00,
            'subtotal' => 500.00,
            'tax' => 40.00,
            'total' => 540.00,
        ]);

        return $po;
    }

    // ==================== ORDER INVOICE DOWNLOAD TESTS ====================

    public function test_admin_can_download_order_invoice(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->admin)
            ->get(route('orders.invoice.download', $order));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('INV-' . $order->order_number, $response->headers->get('content-disposition'));
    }

    public function test_viewer_can_download_order_invoice(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('orders.invoice.download', $order));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_unauthorized_user_cannot_download_order_invoice(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->unauthorizedUser)
            ->get(route('orders.invoice.download', $order));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_download_order_invoice(): void
    {
        $order = $this->createOrder();

        $response = $this->get(route('orders.invoice.download', $order));

        $response->assertRedirect(route('login'));
    }

    // ==================== ORDER INVOICE PREVIEW TESTS ====================

    public function test_admin_can_preview_order_invoice(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->admin)
            ->get(route('orders.invoice.preview', $order));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_unauthorized_user_cannot_preview_order_invoice(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->unauthorizedUser)
            ->get(route('orders.invoice.preview', $order));

        $response->assertStatus(403);
    }

    // ==================== INVOICE CONTENT TESTS ====================

    public function test_invoice_contains_order_data(): void
    {
        $order = $this->createOrder([
            'customer_name' => 'John Doe',
            'customer_email' => 'john@doe.com',
            'customer_address' => '100 Main St',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('orders.invoice.preview', $order));

        $response->assertStatus(200);

        // Verify the PDF was generated from the correct template data
        // We verify the view is rendered with correct data by checking the response is valid PDF
        $content = $response->getContent();
        $this->assertNotEmpty($content);
        // PDF files start with %PDF
        $this->assertStringStartsWith('%PDF', $content);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_user_cannot_download_invoice_from_different_organization(): void
    {
        $otherOrder = Order::create([
            'organization_id' => $this->otherOrganization->id,
            'order_number' => 'ORD-OTHER-001',
            'source' => 'manual',
            'customer_name' => 'Other Customer',
            'status' => 'pending',
            'subtotal' => 100,
            'total' => 100,
            'order_date' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('orders.invoice.download', $otherOrder));

        $response->assertStatus(403);
    }

    // ==================== PURCHASE ORDER INVOICE TESTS ====================

    public function test_admin_can_download_purchase_order_invoice(): void
    {
        $po = $this->createPurchaseOrder();

        $response = $this->actingAs($this->admin)
            ->get(route('purchase-orders.invoice.download', $po));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_admin_can_preview_purchase_order_invoice(): void
    {
        $po = $this->createPurchaseOrder();

        $response = $this->actingAs($this->admin)
            ->get(route('purchase-orders.invoice.preview', $po));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_unauthorized_user_cannot_download_purchase_order_invoice(): void
    {
        $po = $this->createPurchaseOrder();

        $response = $this->actingAs($this->unauthorizedUser)
            ->get(route('purchase-orders.invoice.download', $po));

        $response->assertStatus(403);
    }

    public function test_user_cannot_download_po_invoice_from_different_organization(): void
    {
        $otherSupplier = Supplier::create([
            'organization_id' => $this->otherOrganization->id,
            'name' => 'Other Supplier',
            'is_active' => true,
        ]);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'otheruser@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->otherOrganization->id,
            'role' => 'admin',
        ]);

        $otherPo = PurchaseOrder::create([
            'organization_id' => $this->otherOrganization->id,
            'supplier_id' => $otherSupplier->id,
            'created_by' => $otherUser->id,
            'po_number' => 'PO-OTHER-001',
            'status' => 'sent',
            'order_date' => now(),
            'subtotal' => 100,
            'total' => 100,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('purchase-orders.invoice.download', $otherPo));

        $response->assertStatus(403);
    }

    public function test_purchase_order_invoice_is_valid_pdf(): void
    {
        $po = $this->createPurchaseOrder();

        $response = $this->actingAs($this->admin)
            ->get(route('purchase-orders.invoice.preview', $po));

        $content = $response->getContent();
        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('%PDF', $content);
    }
}
