<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Role;
use App\Models\User;
use App\Services\WarehouseAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A user with no warehouse assignment is unrestricted (unless the
 * organization restricts unassigned users), so removing someone's last
 * assignment widens their access. A warehouse-restricted manager must not be
 * able to do that to themselves or to a colleague.
 */
class WarehouseAssignmentEscalationTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();

        $role = Role::create([
            'name' => 'Warehouse Manager',
            'slug' => 'warehouse-manager-'.uniqid(),
            'organization_id' => $this->organization->id,
            'is_system' => false,
            'permissions' => ['view_warehouses', 'edit_warehouses', 'manage_warehouse_users'],
        ]);

        $this->manager = User::factory()->forOrganization($this->organization->id)->create(['name' => 'Alpha Manager']);
        $this->manager->roles()->attach($role->id);
        $this->warehouseA->users()->attach($this->manager->id);
    }

    private function assignmentIds(): array
    {
        return $this->warehouseA->users()->pluck('users.id')->sort()->values()->all();
    }

    public function test_restricted_manager_cannot_remove_their_own_last_assignment(): void
    {
        $this->actingAs($this->manager)
            ->post(route('warehouses.users.update', $this->warehouseA), ['user_ids' => [$this->restricted->id]])
            ->assertSessionHasErrors('user_ids');

        $this->assertDatabaseHas('warehouse_user', ['warehouse_id' => $this->warehouseA->id, 'user_id' => $this->manager->id]);
        $this->assertTrue(app(WarehouseAccessService::class)->isRestricted($this->manager->fresh()));
    }

    public function test_restricted_manager_cannot_clear_every_assignment(): void
    {
        $this->actingAs($this->manager)
            ->post(route('warehouses.users.update', $this->warehouseA), ['user_ids' => []])
            ->assertSessionHasErrors('user_ids');

        $this->assertSame(
            collect([$this->manager->id, $this->restricted->id])->sort()->values()->all(),
            $this->assignmentIds(),
        );
    }

    public function test_restricted_manager_cannot_remove_their_own_last_assignment_when_org_restricts_unassigned_users(): void
    {
        app(WarehouseAccessService::class)->setOrganizationRestrictsToAssigned($this->organization->id, true);

        $this->actingAs($this->manager)
            ->post(route('warehouses.users.update', $this->warehouseA), ['user_ids' => [$this->restricted->id]])
            ->assertSessionHasErrors('user_ids');

        $this->assertDatabaseHas('warehouse_user', ['warehouse_id' => $this->warehouseA->id, 'user_id' => $this->manager->id]);
    }

    public function test_restricted_manager_cannot_remove_a_colleagues_only_assignment(): void
    {
        $this->actingAs($this->manager)
            ->post(route('warehouses.users.update', $this->warehouseA), ['user_ids' => [$this->manager->id]])
            ->assertSessionHasErrors('user_ids');

        $this->assertDatabaseHas('warehouse_user', ['warehouse_id' => $this->warehouseA->id, 'user_id' => $this->restricted->id]);
        $this->assertSame([$this->warehouseA->id], app(WarehouseAccessService::class)->accessibleWarehouseIds($this->restricted->fresh()));
    }

    public function test_restricted_manager_can_remove_a_colleague_who_keeps_another_assignment(): void
    {
        $this->warehouseB->users()->attach($this->restricted->id);

        $this->actingAs($this->manager)
            ->post(route('warehouses.users.update', $this->warehouseA), ['user_ids' => [$this->manager->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->manager->id], $this->assignmentIds());
        $this->assertSame([$this->warehouseB->id], app(WarehouseAccessService::class)->accessibleWarehouseIds($this->restricted->fresh()));
    }

    public function test_restricted_manager_can_remove_a_colleagues_last_assignment_when_org_restricts_unassigned_users(): void
    {
        // With the organization setting on, an unassigned user sees no
        // warehouse at all, so the removal narrows access instead of widening it.
        app(WarehouseAccessService::class)->setOrganizationRestrictsToAssigned($this->organization->id, true);

        $this->actingAs($this->manager)
            ->post(route('warehouses.users.update', $this->warehouseA), ['user_ids' => [$this->manager->id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->manager->id], $this->assignmentIds());
        $this->assertSame([], app(WarehouseAccessService::class)->accessibleWarehouseIds($this->restricted->fresh()));
    }

    public function test_restricted_manager_can_still_add_users(): void
    {
        $this->actingAs($this->manager)
            ->post(route('warehouses.users.update', $this->warehouseA), [
                'user_ids' => [$this->manager->id, $this->restricted->id, $this->unassigned->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            collect([$this->manager->id, $this->restricted->id, $this->unassigned->id])->sort()->values()->all(),
            $this->assignmentIds(),
        );
    }

    public function test_restricted_manager_cannot_reach_another_warehouse(): void
    {
        $this->actingAs($this->manager)
            ->post(route('warehouses.users.update', $this->warehouseB), ['user_ids' => [$this->manager->id]])
            ->assertForbidden();

        $this->assertDatabaseMissing('warehouse_user', ['warehouse_id' => $this->warehouseB->id]);
    }

    public function test_admin_can_still_clear_every_assignment(): void
    {
        $this->actingAs($this->admin)
            ->post(route('warehouses.users.update', $this->warehouseA), ['user_ids' => []])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->assignmentIds());
    }

    public function test_user_with_all_warehouse_access_can_clear_every_assignment(): void
    {
        $role = Role::create([
            'name' => 'Regional Manager',
            'slug' => 'regional-manager-'.uniqid(),
            'organization_id' => $this->organization->id,
            'is_system' => false,
            'permissions' => ['view_warehouses', 'manage_warehouse_users', 'access_all_warehouses'],
        ]);
        $regional = User::factory()->forOrganization($this->organization->id)->create();
        $regional->roles()->attach($role->id);

        $this->actingAs($regional)
            ->post(route('warehouses.users.update', $this->warehouseA), ['user_ids' => []])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->assignmentIds());
    }
}
