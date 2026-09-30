<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The web warehouse resource used to be guarded by view_warehouses alone, so
 * any read-only user could create, edit and delete warehouses. Each write
 * verb now needs its own permission, matching the REST API.
 */
class WarehouseWritePermissionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Acme',
            'email' => 'acme@example.com',
            'currency' => 'CAD',
            'timezone' => 'America/Toronto',
        ]);

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->organization->id,
            'name' => 'Main',
            'code' => 'MAIN',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::create([
            'name' => 'Member '.count($permissions),
            'email' => 'member'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $user->forceFill(['role' => 'member'])->save();

        $role = Role::create([
            'name' => 'Role '.uniqid(),
            'slug' => 'role-'.uniqid(),
            'organization_id' => $this->organization->id,
            'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_a_view_only_user_can_list_and_view_warehouses(): void
    {
        $viewer = $this->userWith(['view_warehouses']);

        $this->actingAs($viewer)->get(route('warehouses.index'))->assertOk();
        $this->actingAs($viewer)->get(route('warehouses.show', $this->warehouse))->assertOk();
    }

    public function test_a_view_only_user_cannot_create_a_warehouse(): void
    {
        $viewer = $this->userWith(['view_warehouses']);

        $this->actingAs($viewer)->get(route('warehouses.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('warehouses.store'), [
            'name' => 'Sneaky',
            'code' => 'SNK',
        ])->assertForbidden();

        $this->assertDatabaseMissing('warehouses', ['code' => 'SNK']);
    }

    public function test_a_view_only_user_cannot_edit_a_warehouse(): void
    {
        $viewer = $this->userWith(['view_warehouses']);

        $this->actingAs($viewer)->get(route('warehouses.edit', $this->warehouse))->assertForbidden();
        $this->actingAs($viewer)->put(route('warehouses.update', $this->warehouse), [
            'name' => 'Renamed',
            'code' => 'MAIN',
        ])->assertForbidden();
        $this->actingAs($viewer)->patch(route('warehouses.update', $this->warehouse), [
            'name' => 'Renamed',
            'code' => 'MAIN',
        ])->assertForbidden();

        $this->assertSame('Main', $this->warehouse->fresh()->name);
    }

    public function test_a_view_only_user_cannot_delete_a_warehouse(): void
    {
        $viewer = $this->userWith(['view_warehouses']);

        $this->actingAs($viewer)->delete(route('warehouses.destroy', $this->warehouse))->assertForbidden();

        $this->assertNotNull(Warehouse::find($this->warehouse->id));
    }

    public function test_a_warehouse_user_manager_can_open_the_edit_page_but_not_update_details(): void
    {
        $manager = $this->userWith(['view_warehouses', 'manage_warehouse_users']);

        $this->actingAs($manager)->get(route('warehouses.edit', $this->warehouse))->assertOk();
        $this->actingAs($manager)->put(route('warehouses.update', $this->warehouse), [
            'name' => 'Renamed',
            'code' => 'MAIN',
        ])->assertForbidden();
    }

    public function test_each_write_verb_is_open_to_its_own_permission(): void
    {
        $creator = $this->userWith(['view_warehouses', 'create_warehouses']);
        $this->actingAs($creator)->get(route('warehouses.create'))->assertOk();
        $this->actingAs($creator)->post(route('warehouses.store'), [
            'name' => 'Second',
            'code' => 'SEC',
        ])->assertRedirect();
        $this->assertDatabaseHas('warehouses', ['code' => 'SEC']);

        $editor = $this->userWith(['view_warehouses', 'edit_warehouses']);
        $this->actingAs($editor)->get(route('warehouses.edit', $this->warehouse))->assertOk();
        $this->actingAs($editor)->put(route('warehouses.update', $this->warehouse), [
            'name' => 'Renamed',
            'code' => 'MAIN',
        ])->assertRedirect();
        $this->assertSame('Renamed', $this->warehouse->fresh()->name);

        $second = Warehouse::where('code', 'SEC')->firstOrFail();
        $deleter = $this->userWith(['view_warehouses', 'delete_warehouses']);
        $this->actingAs($deleter)->delete(route('warehouses.destroy', $second))->assertRedirect();
        $this->assertNull(Warehouse::find($second->id));
    }
}
