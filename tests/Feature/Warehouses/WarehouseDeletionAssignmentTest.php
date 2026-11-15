<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WarehouseAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deleting a warehouse drops every assignment to it. A user left with no
 * assignment is unrestricted (unless the organization restricts unassigned
 * users), so deletion must follow the same last-assignment rule as editing
 * a warehouse's users: a restricted actor may not delete their own last
 * warehouse, and only admins or holders of access_all_warehouses may drop a
 * colleague's last one.
 */
class WarehouseDeletionAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    /** Empty, not default: deletable. */
    private Warehouse $warehouseC;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();

        $this->warehouseC = Warehouse::factory()->create([
            'organization_id' => $this->organization->id, 'name' => 'Charlie Warehouse', 'code' => 'WH-CHARLIE',
        ]);

        $role = Role::create([
            'name' => 'Warehouse Manager',
            'slug' => 'warehouse-manager-'.uniqid(),
            'organization_id' => $this->organization->id,
            'is_system' => false,
            'permissions' => ['view_warehouses', 'delete_warehouses'],
        ]);

        $this->manager = User::factory()->forOrganization($this->organization->id)->create(['name' => 'Charlie Manager']);
        $this->manager->roles()->attach($role->id);
        $this->warehouseC->users()->attach($this->manager->id);
    }

    private function access(): WarehouseAccessService
    {
        return app(WarehouseAccessService::class);
    }

    public function test_restricted_user_cannot_delete_their_only_warehouse_on_the_web(): void
    {
        $this->actingAs($this->manager)
            ->delete(route('warehouses.destroy', $this->warehouseC))
            ->assertSessionHasErrors('warehouse')
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($this->warehouseC);
        $this->assertSame([$this->warehouseC->id], $this->access()->accessibleWarehouseIds($this->manager->fresh()));
    }

    public function test_restricted_user_cannot_delete_their_only_warehouse_over_the_api(): void
    {
        Sanctum::actingAs($this->manager, ['*']);

        $this->deleteJson('/api/v1/warehouses/'.$this->warehouseC->id)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'last_assignment');

        $this->assertNotSoftDeleted($this->warehouseC);
        $this->assertDatabaseHas('warehouse_user', ['warehouse_id' => $this->warehouseC->id, 'user_id' => $this->manager->id]);
    }

    public function test_restricted_user_cannot_delete_their_only_warehouse_when_org_restricts_unassigned_users(): void
    {
        $this->access()->setOrganizationRestrictsToAssigned($this->organization->id, true);

        $this->actingAs($this->manager)
            ->delete(route('warehouses.destroy', $this->warehouseC))
            ->assertSessionHasErrors('warehouse');

        $this->assertNotSoftDeleted($this->warehouseC);
    }

    public function test_restricted_user_cannot_delete_a_warehouse_that_is_a_colleagues_only_assignment(): void
    {
        $this->warehouseA->users()->attach($this->manager->id);
        $this->warehouseC->users()->attach($this->unassigned->id);

        $this->actingAs($this->manager)
            ->delete(route('warehouses.destroy', $this->warehouseC))
            ->assertSessionHasErrors('warehouse');

        Sanctum::actingAs($this->manager, ['*']);
        $this->deleteJson('/api/v1/warehouses/'.$this->warehouseC->id)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'last_assignment');

        $this->assertNotSoftDeleted($this->warehouseC);
        $this->assertSame([$this->warehouseC->id], $this->access()->accessibleWarehouseIds($this->unassigned->fresh()));
    }

    public function test_restricted_user_can_delete_a_warehouse_everyone_keeps_another_assignment_for(): void
    {
        $this->warehouseA->users()->attach($this->manager->id);
        $this->warehouseC->users()->attach($this->restricted->id);

        $this->actingAs($this->manager)
            ->delete(route('warehouses.destroy', $this->warehouseC))
            ->assertRedirect(route('warehouses.index'))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($this->warehouseC);
        $this->assertSame([$this->warehouseA->id], $this->access()->accessibleWarehouseIds($this->manager->fresh()));
        $this->assertSame([$this->warehouseA->id], $this->access()->accessibleWarehouseIds($this->restricted->fresh()));
    }

    public function test_colleagues_last_assignment_may_go_when_org_restricts_unassigned_users(): void
    {
        // With the organization setting on, an unassigned user sees no
        // warehouse, so the deletion narrows access instead of widening it.
        $this->access()->setOrganizationRestrictsToAssigned($this->organization->id, true);
        $this->warehouseA->users()->attach($this->manager->id);
        $this->warehouseC->users()->attach($this->unassigned->id);

        $this->actingAs($this->manager)
            ->delete(route('warehouses.destroy', $this->warehouseC))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($this->warehouseC);
        $this->assertSame([], $this->access()->accessibleWarehouseIds($this->unassigned->fresh()));
    }

    public function test_restricted_user_still_cannot_delete_a_warehouse_outside_their_assignments(): void
    {
        $this->actingAs($this->manager)
            ->delete(route('warehouses.destroy', $this->warehouseA))
            ->assertForbidden();

        $this->assertNotSoftDeleted($this->warehouseA);
    }

    public function test_admin_can_delete_a_warehouse_holding_someones_last_assignment(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('warehouses.destroy', $this->warehouseC))
            ->assertRedirect(route('warehouses.index'))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($this->warehouseC);
        $this->assertDatabaseMissing('warehouse_user', ['warehouse_id' => $this->warehouseC->id]);
    }

    public function test_user_with_all_warehouse_access_can_delete_it_over_the_api(): void
    {
        $role = Role::create([
            'name' => 'Regional Manager',
            'slug' => 'regional-manager-'.uniqid(),
            'organization_id' => $this->organization->id,
            'is_system' => false,
            'permissions' => ['view_warehouses', 'delete_warehouses', 'access_all_warehouses'],
        ]);
        $regional = User::factory()->forOrganization($this->organization->id)->create();
        $regional->roles()->attach($role->id);

        Sanctum::actingAs($regional, ['*']);
        $this->deleteJson('/api/v1/warehouses/'.$this->warehouseC->id)->assertOk();

        $this->assertSoftDeleted($this->warehouseC);
    }
}
