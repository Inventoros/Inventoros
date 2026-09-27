<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Enums\Permission;
use App\Models\Role;
use App\Services\WarehouseAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseAccessServiceTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    private WarehouseAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWarehouseAccess();
        $this->access = app(WarehouseAccessService::class);
    }

    public function test_assigned_user_is_restricted_to_their_warehouses(): void
    {
        $this->assertSame([$this->warehouseA->id], $this->access->accessibleWarehouseIds($this->restricted));
        $this->assertTrue($this->access->canAccessLocation($this->restricted, $this->locationA));
        $this->assertFalse($this->access->canAccessLocation($this->restricted, $this->locationB));
        $this->assertFalse($this->access->canAccessLocation($this->restricted, $this->locationB->id));
        $this->assertFalse($this->access->canAccessLocation($this->restricted, null));
    }

    public function test_unassigned_user_keeps_access_to_every_warehouse_by_default(): void
    {
        $this->assertNull($this->access->accessibleWarehouseIds($this->unassigned));
        $this->assertTrue($this->access->canAccessLocation($this->unassigned, $this->locationB));
        $this->assertTrue($this->access->canAccessLocation($this->unassigned, null));
    }

    public function test_org_setting_restricts_unassigned_users_to_nothing(): void
    {
        $this->access->setOrganizationRestrictsToAssigned($this->organization->id, true);

        $this->assertSame([], $this->access->accessibleWarehouseIds($this->unassigned));
        $this->assertFalse($this->access->canAccessLocation($this->unassigned, $this->locationA));
        // Assigned users are unaffected by the setting.
        $this->assertSame([$this->warehouseA->id], $this->access->accessibleWarehouseIds($this->restricted));
    }

    public function test_admin_is_never_restricted_even_when_assigned(): void
    {
        $this->warehouseA->users()->attach($this->admin->id);

        $this->assertNull($this->access->accessibleWarehouseIds($this->admin));
    }

    public function test_access_all_warehouses_permission_lifts_the_restriction(): void
    {
        $role = Role::create([
            'name' => 'Roamer',
            'slug' => 'roamer',
            'organization_id' => $this->organization->id,
            'permissions' => [Permission::ACCESS_ALL_WAREHOUSES->value],
        ]);
        $this->restricted->roles()->attach($role->id);
        $this->restricted->unsetRelation('roles');

        $this->assertNull($this->access->accessibleWarehouseIds($this->restricted));
    }

    public function test_switcher_lists_only_accessible_warehouses(): void
    {
        $this->assertSame(
            [$this->warehouseA->id],
            $this->restricted->accessibleWarehouses()->pluck('warehouses.id')->all()
        );

        // Unassigned users keep every active warehouse, as before.
        $this->assertEqualsCanonicalizing(
            [$this->warehouseA->id, $this->warehouseB->id],
            $this->unassigned->accessibleWarehouses()->pluck('warehouses.id')->all()
        );
    }

    public function test_access_all_warehouses_is_a_known_permission_granted_to_org_wide_templates(): void
    {
        $this->assertSame('Warehouse Management', Permission::ACCESS_ALL_WAREHOUSES->category());

        $inventoryManager = collect(\App\Models\PermissionSet::getDefaultTemplates())->firstWhere('slug', 'inventory-manager');
        $this->assertContains('access_all_warehouses', $inventoryManager['permissions']);

        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->assertContains('access_all_warehouses', Role::where('slug', 'system-manager')->first()->permissions);
        $this->assertNotContains('access_all_warehouses', Role::where('slug', 'system-member')->first()->permissions);
    }
}
