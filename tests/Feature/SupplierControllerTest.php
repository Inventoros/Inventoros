<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\Supplier;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected User $viewOnlyUser;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Mark system as installed
        SystemSetting::set('installed', true, 'boolean');

        // Create test organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'phone' => '123-456-7890',
            'address' => '123 Test St',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        // Create admin user with full permissions
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        // Create member with limited permissions
        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        // Create view-only user
        $this->viewOnlyUser = User::create([
            'name' => 'View Only User',
            'email' => 'viewer@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        // Create system roles
        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        // Admin role with full permissions
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => [
                    'view_suppliers',
                    'create_suppliers',
                    'edit_suppliers',
                    'delete_suppliers',
                ],
            ]
        );

        // Member role with create/edit but no delete
        $memberRole = Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'description' => 'Basic member access',
                'is_system' => true,
                'permissions' => [
                    'view_suppliers',
                    'create_suppliers',
                    'edit_suppliers',
                ],
            ]
        );

        // View-only role
        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'description' => 'View only access',
                'is_system' => true,
                'permissions' => ['view_suppliers'],
            ]
        );

        // Assign roles to users
        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createSupplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'Test Supplier',
            'code' => 'SUP-001',
            'contact_name' => 'John Smith',
            'email' => 'supplier@test.com',
            'phone' => '555-1234',
            'address' => '123 Supplier Ave',
            'city' => 'Supplier City',
            'state' => 'SC',
            'zip_code' => '12345',
            'country' => 'USA',
            'website' => 'https://supplier.com',
            'payment_terms' => 'Net 30',
            'currency' => 'USD',
            'is_active' => true,
        ], $attributes));
    }

    // ==================== INDEX TESTS ====================

    public function test_admin_can_view_suppliers_list(): void
    {
        $supplier = $this->createSupplier();

        $response = $this->actingAs($this->admin)
            ->get(route('suppliers.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Suppliers/Index')
            ->has('suppliers')
            ->has('filters')
        );
    }

    public function test_member_can_view_suppliers_list(): void
    {
        $this->createSupplier();

        $response = $this->actingAs($this->member)
            ->get(route('suppliers.index'));

        $response->assertStatus(200);
    }

    public function test_suppliers_list_can_be_searched(): void
    {
        $this->createSupplier(['name' => 'Acme Supplies']);
        $this->createSupplier(['name' => 'Beta Industries']);

        $response = $this->actingAs($this->admin)
            ->get(route('suppliers.index', ['search' => 'Acme']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Suppliers/Index')
            ->where('filters.search', 'Acme')
        );
    }

    public function test_suppliers_list_can_be_filtered_by_active_status(): void
    {
        $this->createSupplier(['name' => 'Active Supplier', 'is_active' => true]);
        $this->createSupplier(['name' => 'Inactive Supplier', 'is_active' => false]);

        $response = $this->actingAs($this->admin)
            ->get(route('suppliers.index', ['is_active' => 'true']));

        $response->assertStatus(200);
    }

    public function test_guest_cannot_view_suppliers_list(): void
    {
        $response = $this->get(route('suppliers.index'));

        $response->assertRedirect(route('login'));
    }

    // ==================== CREATE TESTS ====================

    public function test_admin_can_view_create_supplier_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('suppliers.create'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Suppliers/Create')
        );
    }

    public function test_member_can_view_create_supplier_form(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('suppliers.create'));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_create_supplier_form(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('suppliers.create'));

        $response->assertStatus(403);
    }

    // ==================== STORE TESTS ====================

    public function test_admin_can_create_supplier(): void
    {
        $supplierData = [
            'name' => 'New Supplier',
            'code' => 'SUP-NEW',
            'contact_name' => 'Jane Doe',
            'email' => 'jane@newsupplier.com',
            'phone' => '555-9999',
            'address' => '456 New Supplier St',
            'city' => 'New City',
            'state' => 'NC',
            'zip_code' => '54321',
            'country' => 'USA',
            'website' => 'https://newsupplier.com',
            'payment_terms' => 'Net 60',
            'currency' => 'USD',
            'is_active' => true,
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('suppliers.store'), $supplierData);

        $response->assertRedirect(route('suppliers.index'));
        $response->assertSessionHas('success', 'Supplier created successfully.');

        $this->assertDatabaseHas('suppliers', [
            'name' => 'New Supplier',
            'code' => 'SUP-NEW',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_member_can_create_supplier(): void
    {
        $supplierData = [
            'name' => 'Member Supplier',
            'email' => 'member@supplier.com',
        ];

        $response = $this->actingAs($this->member)
            ->post(route('suppliers.store'), $supplierData);

        $response->assertRedirect(route('suppliers.index'));
        $this->assertDatabaseHas('suppliers', ['name' => 'Member Supplier']);
    }

    public function test_view_only_user_cannot_create_supplier(): void
    {
        $supplierData = [
            'name' => 'Unauthorized Supplier',
        ];

        $response = $this->actingAs($this->viewOnlyUser)
            ->post(route('suppliers.store'), $supplierData);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('suppliers', ['name' => 'Unauthorized Supplier']);
    }

    public function test_supplier_creation_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('suppliers.store'), [
                'name' => '',
            ]);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_supplier_creation_validates_email_format(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('suppliers.store'), [
                'name' => 'Test Supplier',
                'email' => 'invalid-email',
            ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_supplier_creation_validates_website_url(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('suppliers.store'), [
                'name' => 'Test Supplier',
                'website' => 'not-a-url',
            ]);

        $response->assertSessionHasErrors(['website']);
    }

    public function test_supplier_defaults_to_active(): void
    {
        $supplierData = [
            'name' => 'Default Active Supplier',
        ];

        $this->actingAs($this->admin)
            ->post(route('suppliers.store'), $supplierData);

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Default Active Supplier',
            'is_active' => true,
        ]);
    }

    // ==================== SHOW TESTS ====================

    public function test_admin_can_view_supplier(): void
    {
        $supplier = $this->createSupplier();

        $response = $this->actingAs($this->admin)
            ->get(route('suppliers.show', $supplier));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Suppliers/Show')
            ->has('supplier')
        );
    }

    public function test_member_can_view_supplier(): void
    {
        $supplier = $this->createSupplier();

        $response = $this->actingAs($this->member)
            ->get(route('suppliers.show', $supplier));

        $response->assertStatus(200);
    }

    public function test_user_cannot_view_supplier_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherSupplier = Supplier::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Org Supplier',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('suppliers.show', $otherSupplier));

        $response->assertStatus(404);
    }

    // ==================== EDIT TESTS ====================

    public function test_admin_can_view_edit_supplier_form(): void
    {
        $supplier = $this->createSupplier();

        $response = $this->actingAs($this->admin)
            ->get(route('suppliers.edit', $supplier));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Suppliers/Edit')
            ->has('supplier')
        );
    }

    public function test_member_can_view_edit_supplier_form(): void
    {
        $supplier = $this->createSupplier();

        $response = $this->actingAs($this->member)
            ->get(route('suppliers.edit', $supplier));

        $response->assertStatus(200);
    }

    public function test_view_only_user_cannot_view_edit_supplier_form(): void
    {
        $supplier = $this->createSupplier();

        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('suppliers.edit', $supplier));

        $response->assertStatus(403);
    }

    public function test_user_cannot_edit_supplier_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherSupplier = Supplier::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Org Supplier',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('suppliers.edit', $otherSupplier));

        $response->assertStatus(404);
    }

    // ==================== UPDATE TESTS ====================

    public function test_admin_can_update_supplier(): void
    {
        $supplier = $this->createSupplier(['name' => 'Original Supplier']);

        $response = $this->actingAs($this->admin)
            ->put(route('suppliers.update', $supplier), [
                'name' => 'Updated Supplier',
                'contact_name' => 'New Contact',
                'email' => 'updated@supplier.com',
            ]);

        $response->assertRedirect(route('suppliers.index'));
        $response->assertSessionHas('success', 'Supplier updated successfully.');

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Updated Supplier',
            'contact_name' => 'New Contact',
        ]);
    }

    public function test_member_can_update_supplier(): void
    {
        $supplier = $this->createSupplier(['name' => 'Original Supplier']);

        $response = $this->actingAs($this->member)
            ->put(route('suppliers.update', $supplier), [
                'name' => 'Member Updated',
            ]);

        $response->assertRedirect(route('suppliers.index'));
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Member Updated',
        ]);
    }

    public function test_view_only_user_cannot_update_supplier(): void
    {
        $supplier = $this->createSupplier(['name' => 'Original Supplier']);

        $response = $this->actingAs($this->viewOnlyUser)
            ->put(route('suppliers.update', $supplier), [
                'name' => 'Should Not Update',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Original Supplier',
        ]);
    }

    public function test_user_cannot_update_supplier_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherSupplier = Supplier::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Org Supplier',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('suppliers.update', $otherSupplier), [
                'name' => 'Hacked Supplier',
            ]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('suppliers', [
            'id' => $otherSupplier->id,
            'name' => 'Other Org Supplier',
        ]);
    }

    public function test_supplier_can_be_deactivated(): void
    {
        $supplier = $this->createSupplier(['is_active' => true]);

        $response = $this->actingAs($this->admin)
            ->put(route('suppliers.update', $supplier), [
                'is_active' => false,
            ]);

        $response->assertRedirect(route('suppliers.index'));
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'is_active' => false,
        ]);
    }

    // ==================== DELETE TESTS ====================

    public function test_admin_can_delete_supplier_without_products(): void
    {
        $supplier = $this->createSupplier();

        $response = $this->actingAs($this->admin)
            ->delete(route('suppliers.destroy', $supplier));

        $response->assertRedirect(route('suppliers.index'));
        $response->assertSessionHas('success', 'Supplier deleted successfully.');

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
    }

    public function test_member_cannot_delete_supplier(): void
    {
        $supplier = $this->createSupplier();

        $response = $this->actingAs($this->member)
            ->delete(route('suppliers.destroy', $supplier));

        $response->assertStatus(403);
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'deleted_at' => null,
        ]);
    }

    public function test_view_only_user_cannot_delete_supplier(): void
    {
        $supplier = $this->createSupplier();

        $response = $this->actingAs($this->viewOnlyUser)
            ->delete(route('suppliers.destroy', $supplier));

        $response->assertStatus(403);
    }

    public function test_cannot_delete_supplier_with_associated_products(): void
    {
        $supplier = $this->createSupplier();

        // Create a product and associate it with the supplier
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $supplier->products()->attach($product->id, [
            'cost_price' => 50.00,
            'supplier_sku' => 'SUP-PROD-001',
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('suppliers.destroy', $supplier));

        $response->assertRedirect(route('suppliers.index'));
        $response->assertSessionHas('error', 'Cannot delete supplier with associated products.');

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'deleted_at' => null,
        ]);
    }

    public function test_user_cannot_delete_supplier_from_different_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        $otherSupplier = Supplier::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Org Supplier',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('suppliers.destroy', $otherSupplier));

        $response->assertStatus(404);
        $this->assertDatabaseHas('suppliers', [
            'id' => $otherSupplier->id,
            'deleted_at' => null,
        ]);
    }

    // ==================== ORGANIZATION ISOLATION TESTS ====================

    public function test_suppliers_list_only_shows_organization_suppliers(): void
    {
        // Create supplier for current organization
        $ownSupplier = $this->createSupplier(['name' => 'Own Supplier']);

        // Create supplier for different organization
        $otherOrg = Organization::create([
            'name' => 'Other Organization',
            'email' => 'other@org.com',
        ]);

        Supplier::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Org Supplier',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('suppliers.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Suppliers/Index')
            ->has('suppliers.data', 1)
        );
    }

    // ==================== MODEL TESTS ====================

    public function test_supplier_full_address_attribute(): void
    {
        $supplier = $this->createSupplier([
            'address' => '123 Main St',
            'city' => 'New York',
            'state' => 'NY',
            'zip_code' => '10001',
            'country' => 'USA',
        ]);

        $this->assertEquals(
            '123 Main St, New York, NY, 10001, USA',
            $supplier->full_address
        );
    }

    public function test_supplier_organization_relationship(): void
    {
        $supplier = $this->createSupplier();
        $supplier->load('organization');

        $this->assertNotNull($supplier->organization);
        $this->assertEquals($this->organization->id, $supplier->organization->id);
    }

    public function test_supplier_products_relationship(): void
    {
        $supplier = $this->createSupplier();

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'price' => 99.99,
            'currency' => 'USD',
            'stock' => 10,
            'min_stock' => 1,
        ]);

        $supplier->products()->attach($product->id, [
            'cost_price' => 50.00,
            'supplier_sku' => 'SUP-PROD-001',
            'lead_time_days' => 7,
            'minimum_order_quantity' => 10,
            'is_primary' => true,
        ]);

        $supplier->load('products');

        $this->assertCount(1, $supplier->products);
        $this->assertEquals($product->id, $supplier->products->first()->id);
        $this->assertEquals(50.00, $supplier->products->first()->pivot->cost_price);
        $this->assertTrue($supplier->products->first()->pivot->is_primary);
    }

    // ==================== SCOPE TESTS ====================

    public function test_supplier_active_scope(): void
    {
        $this->createSupplier(['name' => 'Active 1', 'is_active' => true]);
        $this->createSupplier(['name' => 'Active 2', 'is_active' => true]);
        $this->createSupplier(['name' => 'Inactive', 'is_active' => false]);

        $activeSuppliers = Supplier::forOrganization($this->organization->id)
            ->active()
            ->get();

        $this->assertCount(2, $activeSuppliers);
    }

    public function test_supplier_search_scope(): void
    {
        $this->createSupplier(['name' => 'Acme Supplies', 'code' => 'ACM-001']);
        $this->createSupplier(['name' => 'Beta Industries', 'code' => 'BET-001']);

        $results = Supplier::forOrganization($this->organization->id)
            ->search('Acme')
            ->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Acme Supplies', $results->first()->name);
    }
}
