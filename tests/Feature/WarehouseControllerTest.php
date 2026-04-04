<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\Product;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'CAD',
            'timezone' => 'America/Toronto',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->admin->forceFill(['role' => 'admin'])->save();

        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->member->forceFill(['role' => 'member'])->save();

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => [
                    'view_warehouses', 'create_warehouses', 'edit_warehouses',
                    'delete_warehouses', 'manage_warehouse_users',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => [],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'Test Warehouse',
            'code' => 'WH-TEST',
            'city' => 'Toronto',
            'province' => 'ON',
            'country' => 'CA',
            'is_default' => false,
            'is_active' => true,
            'priority' => 0,
        ], $attributes));
    }

    // ---------------------------------------------------------------
    // CRUD Operations
    // ---------------------------------------------------------------

    public function test_admin_can_view_warehouses_list(): void
    {
        $this->createWarehouse();

        $response = $this->actingAs($this->admin)
            ->get(route('warehouses.index'));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_create_warehouse_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('warehouses.create'));

        $response->assertStatus(200);
    }

    public function test_admin_can_create_warehouse(): void
    {
        $data = [
            'name' => 'Main Warehouse',
            'code' => 'WH-MAIN',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'is_active' => true,
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('warehouses.store'), $data);

        $response->assertRedirect();

        $this->assertDatabaseHas('warehouses', [
            'name' => 'Main Warehouse',
            'code' => 'WH-MAIN',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_first_warehouse_becomes_default_automatically(): void
    {
        // Ensure no warehouses exist yet
        $this->assertDatabaseCount('warehouses', 0);

        $response = $this->actingAs($this->admin)
            ->post(route('warehouses.store'), [
                'name' => 'First Warehouse',
                'code' => 'WH-FIRST',
                'is_active' => true,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('warehouses', [
            'name' => 'First Warehouse',
            'is_default' => true,
        ]);
    }

    public function test_second_warehouse_is_not_default(): void
    {
        $this->createWarehouse(['is_default' => true, 'code' => 'WH-001']);

        $response = $this->actingAs($this->admin)
            ->post(route('warehouses.store'), [
                'name' => 'Second Warehouse',
                'code' => 'WH-002',
                'is_active' => true,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('warehouses', [
            'name' => 'Second Warehouse',
            'is_default' => false,
        ]);
    }

    public function test_admin_can_view_warehouse(): void
    {
        $warehouse = $this->createWarehouse();

        $response = $this->actingAs($this->admin)
            ->get(route('warehouses.show', $warehouse));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_edit_warehouse_form(): void
    {
        $warehouse = $this->createWarehouse();

        $response = $this->actingAs($this->admin)
            ->get(route('warehouses.edit', $warehouse));

        $response->assertStatus(200);
    }

    public function test_admin_can_update_warehouse(): void
    {
        $warehouse = $this->createWarehouse();

        $response = $this->actingAs($this->admin)
            ->put(route('warehouses.update', $warehouse), [
                'name' => 'Updated Warehouse',
                'code' => $warehouse->code,
                'is_active' => true,
            ]);

        $response->assertRedirect(route('warehouses.show', $warehouse));

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse->id,
            'name' => 'Updated Warehouse',
        ]);
    }

    public function test_admin_can_delete_warehouse(): void
    {
        $warehouse = $this->createWarehouse(['is_default' => false]);

        $response = $this->actingAs($this->admin)
            ->delete(route('warehouses.destroy', $warehouse));

        $response->assertRedirect(route('warehouses.index'));

        $this->assertSoftDeleted('warehouses', [
            'id' => $warehouse->id,
        ]);
    }

    public function test_cannot_delete_default_warehouse(): void
    {
        $warehouse = $this->createWarehouse(['is_default' => true]);

        $response = $this->actingAs($this->admin)
            ->delete(route('warehouses.destroy', $warehouse));

        $response->assertRedirect();
        $response->assertSessionHasErrors('warehouse');

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cannot_delete_warehouse_with_locations_that_have_products(): void
    {
        $warehouse = $this->createWarehouse(['is_default' => false]);

        $location = ProductLocation::create([
            'organization_id' => $this->organization->id,
            'warehouse_id' => $warehouse->id,
            'name' => 'Aisle A',
            'is_active' => true,
        ]);

        // Create a product in this location
        Product::create([
            'organization_id' => $this->organization->id,
            'location_id' => $location->id,
            'name' => 'Test Product',
            'sku' => 'TP-001',
            'price' => 10.00,
            'stock' => 5,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('warehouses.destroy', $warehouse));

        $response->assertRedirect();
        $response->assertSessionHasErrors('warehouse');

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse->id,
            'deleted_at' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // Warehouse Switching (Active Warehouse Session)
    // ---------------------------------------------------------------

    public function test_user_can_set_active_warehouse_via_session(): void
    {
        $warehouse = $this->createWarehouse(['is_active' => true]);

        $response = $this->actingAs($this->admin)
            ->post(route('warehouses.set-active'), [
                'warehouse_id' => $warehouse->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_non_admin_cannot_set_warehouse_they_lack_access_to(): void
    {
        $warehouse = $this->createWarehouse(['is_active' => true]);

        // Member is not assigned to this warehouse and is not admin
        $response = $this->actingAs($this->member)
            ->post(route('warehouses.set-active'), [
                'warehouse_id' => $warehouse->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_can_set_warehouse_they_are_assigned_to(): void
    {
        $warehouse = $this->createWarehouse(['is_active' => true]);

        // Assign member to this warehouse
        $warehouse->users()->attach($this->member->id);

        $response = $this->actingAs($this->member)
            ->post(route('warehouses.set-active'), [
                'warehouse_id' => $warehouse->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_setting_null_clears_warehouse_filter(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('warehouses.set-active'), [
                'warehouse_id' => null,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Viewing all warehouses.');
    }

    // ---------------------------------------------------------------
    // User Assignment
    // ---------------------------------------------------------------

    public function test_admin_can_assign_users_to_warehouse(): void
    {
        $warehouse = $this->createWarehouse();

        $response = $this->actingAs($this->admin)
            ->post(route('warehouses.users.update', $warehouse), [
                'user_ids' => [$this->member->id],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('warehouse_user', [
            'warehouse_id' => $warehouse->id,
            'user_id' => $this->member->id,
        ]);
    }

    public function test_admin_can_remove_users_from_warehouse(): void
    {
        $warehouse = $this->createWarehouse();
        $warehouse->users()->attach([$this->admin->id, $this->member->id]);

        // Sync with only admin removes the member
        $response = $this->actingAs($this->admin)
            ->post(route('warehouses.users.update', $warehouse), [
                'user_ids' => [$this->admin->id],
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('warehouse_user', [
            'warehouse_id' => $warehouse->id,
            'user_id' => $this->admin->id,
        ]);

        $this->assertDatabaseMissing('warehouse_user', [
            'warehouse_id' => $warehouse->id,
            'user_id' => $this->member->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Set Default
    // ---------------------------------------------------------------

    public function test_admin_can_set_warehouse_as_default(): void
    {
        $warehouse1 = $this->createWarehouse(['is_default' => true, 'code' => 'WH-A']);
        $warehouse2 = $this->createWarehouse(['is_default' => false, 'code' => 'WH-B']);

        $response = $this->actingAs($this->admin)
            ->post(route('warehouses.set-default', $warehouse2));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse2->id,
            'is_default' => true,
        ]);
    }

    public function test_setting_new_default_clears_old_default(): void
    {
        $warehouse1 = $this->createWarehouse(['is_default' => true, 'code' => 'WH-A']);
        $warehouse2 = $this->createWarehouse(['is_default' => false, 'code' => 'WH-B']);

        $this->actingAs($this->admin)
            ->post(route('warehouses.set-default', $warehouse2));

        $warehouse1->refresh();
        $warehouse2->refresh();

        $this->assertFalse($warehouse1->is_default);
        $this->assertTrue($warehouse2->is_default);
    }

    // ---------------------------------------------------------------
    // Permission Checks
    // ---------------------------------------------------------------

    public function test_guest_cannot_access_warehouse_pages(): void
    {
        $response = $this->get(route('warehouses.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_member_without_permission_gets_403_on_index(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('warehouses.index'));

        $response->assertStatus(403);
    }

    public function test_member_without_permission_gets_403_on_create(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('warehouses.create'));

        $response->assertStatus(403);
    }

    public function test_member_without_permission_gets_403_on_store(): void
    {
        $response = $this->actingAs($this->member)
            ->post(route('warehouses.store'), [
                'name' => 'Unauthorized Warehouse',
            ]);

        $response->assertStatus(403);
    }

    public function test_member_without_permission_gets_403_on_delete(): void
    {
        $warehouse = $this->createWarehouse();

        $response = $this->actingAs($this->member)
            ->delete(route('warehouses.destroy', $warehouse));

        $response->assertStatus(403);
    }

    public function test_member_without_permission_gets_403_on_user_assignment(): void
    {
        $warehouse = $this->createWarehouse();

        $response = $this->actingAs($this->member)
            ->post(route('warehouses.users.update', $warehouse), [
                'user_ids' => [$this->member->id],
            ]);

        $response->assertStatus(403);
    }

    public function test_member_without_permission_gets_403_on_set_default(): void
    {
        $warehouse = $this->createWarehouse();

        $response = $this->actingAs($this->member)
            ->post(route('warehouses.set-default', $warehouse));

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_warehouse_creation_validates_required_name(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('warehouses.store'), [
                'name' => '',
            ]);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_warehouse_code_must_be_unique_within_organization(): void
    {
        $this->createWarehouse(['code' => 'WH-DUPE']);

        $response = $this->actingAs($this->admin)
            ->post(route('warehouses.store'), [
                'name' => 'Another Warehouse',
                'code' => 'WH-DUPE',
                'is_active' => true,
            ]);

        $response->assertSessionHasErrors(['code']);
    }

    // ---------------------------------------------------------------
    // Cross-org isolation
    // ---------------------------------------------------------------

    public function test_cannot_view_warehouse_from_another_organization(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'email' => 'other@org.com',
            'currency' => 'CAD',
            'timezone' => 'America/Toronto',
        ]);

        $otherWarehouse = Warehouse::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Other Warehouse',
            'code' => 'WH-OTHER',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('warehouses.show', $otherWarehouse));

        $response->assertStatus(403);
    }
}
