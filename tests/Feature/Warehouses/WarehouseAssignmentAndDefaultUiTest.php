<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The warehouse edit/show pages drive user assignment
 * (warehouses.users.update) and the default warehouse
 * (warehouses.set-default).
 */
class WarehouseAssignmentAndDefaultUiTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();
    }

    public function test_edit_page_lists_org_users_and_the_current_assignments(): void
    {
        $outsider = User::factory()->create(['name' => 'Other Org Person']);

        $this->actingAs($this->admin)
            ->get(route('warehouses.edit', $this->warehouseA))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Warehouses/Edit')
                ->has('users', 3)
                ->where('assignedUserIds', [$this->restricted->id])
                ->where('users', fn ($users) => collect($users)->pluck('id')->doesntContain($outsider->id)
                    && collect($users)->every(fn ($u) => array_key_exists('has_all_warehouse_access', $u)))
            );
    }

    public function test_edit_page_flags_users_who_are_never_restricted(): void
    {
        $this->actingAs($this->admin)
            ->get(route('warehouses.edit', $this->warehouseA))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users', fn ($users) => collect($users)->firstWhere('id', $this->admin->id)['has_all_warehouse_access'] === true
                    && collect($users)->firstWhere('id', $this->restricted->id)['has_all_warehouse_access'] === false)
            );
    }

    public function test_assignments_can_be_saved_and_cleared(): void
    {
        $this->actingAs($this->admin)
            ->post(route('warehouses.users.update', $this->warehouseB), ['user_ids' => [$this->unassigned->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('warehouse_user', ['warehouse_id' => $this->warehouseB->id, 'user_id' => $this->unassigned->id]);

        // Clearing every assignment is a valid save, not a validation error.
        $this->actingAs($this->admin)
            ->post(route('warehouses.users.update', $this->warehouseB), ['user_ids' => []])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('warehouse_user', ['warehouse_id' => $this->warehouseB->id]);
    }

    public function test_show_page_passes_locations_assigned_users_and_default_flag(): void
    {
        $this->actingAs($this->admin)
            ->get(route('warehouses.show', $this->warehouseB))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Warehouses/Show')
                ->where('warehouse.is_default', false)
                ->has('locations', 1)
                ->where('locations.0.name', 'Bravo Shelf')
                ->has('assignedUsers', 0)
                ->where('stats.locations_count', 1)
                ->where('stats.on_hand', 90)
            );
    }

    public function test_set_default_moves_the_default_flag(): void
    {
        $this->actingAs($this->admin)
            ->post(route('warehouses.set-default', $this->warehouseB))
            ->assertRedirect();

        $this->assertTrue($this->warehouseB->fresh()->is_default);
        $this->assertFalse($this->warehouseA->fresh()->is_default);
    }
}
