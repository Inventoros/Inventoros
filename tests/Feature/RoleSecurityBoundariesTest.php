<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\PermissionSet;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RoleSecurityBoundariesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Organization $other;
    private User $admin;
    private User $editor;
    private Role $editorRole;
    private Role $systemMember;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');
        $this->organization = Organization::create(['name' => 'Alpha', 'email' => 'alpha@org.test']);
        $this->other = Organization::create(['name' => 'Bravo', 'email' => 'bravo@org.test']);
        $this->admin = User::factory()->admin()->forOrganization($this->organization->id)->create();
        $this->editor = User::factory()->forOrganization($this->organization->id)->create();
        $this->editorRole = Role::create(['name' => 'Delegated editor', 'slug' => 'delegated-editor',
            'organization_id' => $this->organization->id, 'is_system' => false,
            'permissions' => ['view_roles', 'create_roles', 'edit_roles']]);
        $this->editor->roles()->attach($this->editorRole->id);
        $this->systemMember = Role::create(['name' => 'Member', 'slug' => 'system-member',
            'organization_id' => null, 'is_system' => true, 'permissions' => ['view_products']]);
    }

    public function test_tenant_admin_cannot_change_a_shared_system_role_or_other_organizations_permissions(): void
    {
        $foreign = User::factory()->forOrganization($this->other->id)->create();
        $this->assertFalse($foreign->hasPermission('manage_organization'));
        $this->actingAs($this->admin)->put(route('roles.update', $this->systemMember), [
            'name' => 'Member', 'permissions' => ['manage_organization'],
        ])->assertSessionHasErrors(['role']);
        $this->assertSame(['view_products'], $this->systemMember->fresh()->permissions);
        $this->assertFalse($foreign->fresh()->hasPermission('manage_organization'));
    }

    public function test_delegated_editor_cannot_escalate_through_their_own_role(): void
    {
        $this->assertFalse($this->editor->hasPermission('manage_organization'));
        $this->actingAs($this->editor)->putJson(route('roles.update', $this->editorRole), [
            'name' => $this->editorRole->name,
            'permissions' => ['view_roles', 'create_roles', 'edit_roles', 'manage_organization'],
        ])->assertForbidden();
        $this->assertFalse($this->editor->fresh()->hasPermission('manage_organization'));
    }

    public function test_delegated_creator_cannot_embed_permissions_they_do_not_hold(): void
    {
        $this->actingAs($this->editor)->postJson(route('roles.store'), [
            'name' => 'Escalated', 'permissions' => ['manage_organization'],
        ])->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'Escalated']);
    }

    public function test_role_lists_and_details_do_not_serialize_foreign_organization_members(): void
    {
        $foreign = User::factory()->forOrganization($this->other->id)->create(['email' => 'private-foreign-user@bravo.test']);
        $foreign->roles()->attach($this->systemMember->id);
        $this->editor->roles()->attach($this->systemMember->id);
        $this->actingAs($this->admin)->get(route('roles.index'))->assertOk()
            ->assertDontSee('private-foreign-user@bravo.test')->assertSee($this->editor->email);
        $this->get(route('roles.show', $this->systemMember))->assertOk()
            ->assertDontSee('private-foreign-user@bravo.test')->assertSee($this->editor->email);
    }

    public function test_permission_set_ids_are_scoped_to_the_actors_organization_on_create_and_update(): void
    {
        $foreignSet = PermissionSet::create(['name' => 'Foreign', 'organization_id' => $this->other->id,
            'permissions' => ['manage_organization'], 'is_template' => false, 'is_active' => true]);
        $this->actingAs($this->admin)->postJson(route('roles.store'), [
            'name' => 'Foreign set attempt', 'permission_set_ids' => [$foreignSet->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['permission_set_ids.0']);
        $this->putJson(route('roles.update', $this->editorRole), [
            'name' => $this->editorRole->name, 'permission_set_ids' => [$foreignSet->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['permission_set_ids.0']);
        $this->assertSame(0, $this->editorRole->permissionSets()->count());
    }

    public function test_permissions_from_own_permission_sets_cannot_exceed_the_actors_permissions(): void
    {
        $set = PermissionSet::create(['name' => 'Privileged', 'organization_id' => $this->organization->id,
            'permissions' => ['manage_organization'], 'is_template' => false, 'is_active' => true]);
        $this->actingAs($this->editor)->putJson(route('roles.update', $this->editorRole), [
            'name' => $this->editorRole->name, 'permission_set_ids' => [$set->id],
        ])->assertForbidden();
        $this->assertSame(0, $this->editorRole->permissionSets()->count());
    }

    public function test_delegated_editors_can_manage_custom_roles_within_their_permissions(): void
    {
        $this->actingAs($this->editor)->post(route('roles.store'), [
            'name' => 'Narrow role', 'permissions' => ['view_roles'],
        ])->assertRedirect(route('roles.index'));
        $role = Role::where('name', 'Narrow role')->firstOrFail();
        $this->put(route('roles.update', $role), [
            'name' => 'Narrow renamed role', 'permissions' => ['edit_roles'],
        ])->assertRedirect(route('roles.index'));
        $this->assertSame(['edit_roles'], $role->fresh()->permissions);
    }

    public function test_unknown_permission_strings_are_rejected(): void
    {
        $this->actingAs($this->admin)->postJson(route('roles.store'), [
            'name' => 'Unknown', 'permissions' => ['invented_privilege'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_own_sets_and_shared_templates_remain_available_for_authorized_role_management(): void
    {
        $own = PermissionSet::create(['name' => 'Own allowed', 'organization_id' => $this->organization->id,
            'permissions' => ['view_roles'], 'is_template' => false, 'is_active' => true]);
        $template = PermissionSet::create(['name' => 'Shared allowed', 'organization_id' => null,
            'permissions' => ['edit_roles'], 'is_template' => true, 'is_active' => true]);
        $this->actingAs($this->editor)->post(route('roles.store'), [
            'name' => 'Allowed sets', 'permission_set_ids' => [$own->id, $template->id],
        ])->assertRedirect(route('roles.index'));
        $role = Role::where('name', 'Allowed sets')->firstOrFail();
        $this->assertEqualsCanonicalizing([$own->id, $template->id], $role->permissionSets()->pluck('permission_sets.id')->all());
    }

    public function test_a_delegated_editor_cannot_strip_a_more_privileged_roles_permissions(): void
    {
        $role = Role::create(['name' => 'Privileged role', 'organization_id' => $this->organization->id,
            'permissions' => ['manage_organization'], 'is_system' => false]);
        $this->actingAs($this->editor)->putJson(route('roles.update', $role), [
            'name' => $role->name, 'permissions' => ['view_roles'],
        ])->assertForbidden();
        $this->assertSame(['manage_organization'], $role->fresh()->permissions);
    }

    public function test_permission_set_grants_remain_delegable_within_the_actors_permissions(): void
    {
        $set = PermissionSet::create([
            'name' => 'Editor product access', 'organization_id' => $this->organization->id,
            'permissions' => ['view_suppliers'], 'is_template' => false, 'is_active' => true,
        ]);
        $this->editorRole->permissionSets()->attach($set->id);
        $this->editor = $this->editor->fresh();
        $this->assertTrue($this->editor->hasPermission('view_suppliers'));

        $this->actingAs($this->editor)->post(route('roles.store'), [
            'name' => 'Product viewer', 'permissions' => ['view_suppliers'],
        ])->assertRedirect(route('roles.index'));
        $role = Role::where('name', 'Product viewer')->firstOrFail();
        $this->put(route('roles.update', $role), [
            'name' => 'Renamed product viewer', 'permissions' => ['view_suppliers'],
        ])->assertRedirect(route('roles.index'));
        $this->assertSame(['view_suppliers'], $role->fresh()->permissions);
    }
    private function delegatedDeleter(): User
    {
        $deleter = User::factory()->forOrganization($this->organization->id)->create();
        $role = Role::create(['name' => 'Delegated deleter', 'organization_id' => $this->organization->id,
            'is_system' => false, 'permissions' => ['view_roles', 'delete_roles', 'view_products']]);
        $deleter->roles()->attach($role->id);

        return $deleter;
    }

    public function test_a_delegated_deleter_cannot_delete_a_more_privileged_role(): void
    {
        $role = Role::create(['name' => 'Privileged role', 'organization_id' => $this->organization->id,
            'permissions' => ['manage_organization'], 'is_system' => false]);

        // Refused like the other delete guards, so the role page shows why.
        $this->actingAs($this->delegatedDeleter())
            ->from(route('roles.show', $role))
            ->delete(route('roles.destroy', $role))
            ->assertRedirect(route('roles.show', $role))
            ->assertSessionHasErrors(['role' => 'You cannot delete a role with permissions you do not hold.']);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_a_delegated_deleter_cannot_delete_a_role_granted_more_through_a_permission_set(): void
    {
        $set = PermissionSet::create(['name' => 'Privileged set', 'organization_id' => $this->organization->id,
            'permissions' => ['manage_organization'], 'is_template' => false, 'is_active' => true]);
        $role = Role::create(['name' => 'Set role', 'organization_id' => $this->organization->id,
            'permissions' => ['view_products'], 'is_system' => false]);
        $role->permissionSets()->attach($set->id);

        $this->actingAs($this->delegatedDeleter())->delete(route('roles.destroy', $role))
            ->assertSessionHasErrors(['role' => 'You cannot delete a role with permissions you do not hold.']);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_permissions_of_a_plugin_that_is_no_longer_active_do_not_block_deleting_a_role(): void
    {
        // A permission string left behind by a removed plugin grants nothing.
        $role = Role::create(['name' => 'Stale role', 'organization_id' => $this->organization->id,
            'permissions' => ['view_products', 'removed-plugin.manage_widgets'], 'is_system' => false]);

        $this->actingAs($this->delegatedDeleter())->delete(route('roles.destroy', $role))
            ->assertRedirect(route('roles.index'));
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_permissions_of_an_active_plugin_still_block_deleting_a_role(): void
    {
        register_permission('active-plugin.manage_widgets', 'Manage widgets');
        $role = Role::create(['name' => 'Plugin role', 'organization_id' => $this->organization->id,
            'permissions' => ['view_products', 'active-plugin.manage_widgets'], 'is_system' => false]);

        $this->actingAs($this->delegatedDeleter())->delete(route('roles.destroy', $role))
            ->assertSessionHasErrors(['role' => 'You cannot delete a role with permissions you do not hold.']);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_a_delegated_deleter_can_delete_a_role_within_their_permissions(): void
    {
        $role = Role::create(['name' => 'Narrow role', 'organization_id' => $this->organization->id,
            'permissions' => ['view_products'], 'is_system' => false]);

        $this->actingAs($this->delegatedDeleter())->delete(route('roles.destroy', $role))
            ->assertRedirect(route('roles.index'));
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_a_tenant_admin_can_delete_any_unassigned_custom_role_but_not_another_organizations(): void
    {
        $role = Role::create(['name' => 'Privileged role', 'organization_id' => $this->organization->id,
            'permissions' => ['manage_organization'], 'is_system' => false]);
        $foreign = Role::create(['name' => 'Foreign role', 'organization_id' => $this->other->id,
            'permissions' => ['view_products'], 'is_system' => false]);

        $this->actingAs($this->admin)->delete(route('roles.destroy', $role))->assertRedirect(route('roles.index'));
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);

        $this->deleteJson(route('roles.destroy', $foreign))->assertForbidden();
        $this->assertDatabaseHas('roles', ['id' => $foreign->id]);
    }
}
