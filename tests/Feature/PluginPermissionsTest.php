<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\PermissionSet;
use App\Models\Role;
use App\Services\PluginService;
use App\Services\Plugins\PluginPermissionRegistry;
use App\Services\PluginUIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * register_permission() lets an active plugin add permissions of its own,
 * named "{slug}.{ability}", which roles, permission sets and API tokens can
 * then grant like core permissions.
 */
final class PluginPermissionsTest extends TestCase
{
    use BuildsApiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
    }

    private function registerFixture(): void
    {
        register_permission('fixture-plugin.manage', 'Manage Fixture', 'Can manage the fixture plugin', 'Fixture');
    }

    public function test_names_must_be_slug_dot_ability(): void
    {
        $registry = app(PluginPermissionRegistry::class);

        foreach (['view_products', 'Fixture.manage', 'fixture.', '.manage', 'fixture.manage.more', 'fixture manage', 'fixture.Manage', '-fixture.manage'] as $bad) {
            try {
                $registry->register($bad, 'Bad');
                $this->fail("\"{$bad}\" should be refused");
            } catch (InvalidArgumentException) {
                $this->assertFalse($registry->has($bad));
            }
        }

        $registry->register('fixture-plugin.manage_all', 'Good');
        $this->assertTrue($registry->has('fixture-plugin.manage_all'));
    }

    public function test_registering_again_replaces_the_label(): void
    {
        register_permission('fixture-plugin.manage', 'Old');
        register_permission('fixture-plugin.manage', 'New', 'Desc');

        $this->assertSame(['fixture-plugin.manage'], app(PluginPermissionRegistry::class)->names());
        $this->assertSame('New', app(PluginPermissionRegistry::class)->get('fixture-plugin.manage')['label']);
    }

    public function test_grouped_permissions_list_registered_plugin_permissions(): void
    {
        $this->assertArrayNotHasKey('Fixture', Permission::grouped());

        $this->registerFixture();
        register_permission('fixture-plugin.view', 'View Fixture');

        $grouped = Permission::grouped();
        $this->assertSame([
            'value' => 'fixture-plugin.manage',
            'label' => 'Manage Fixture',
            'description' => 'Can manage the fixture plugin',
        ], $grouped['Fixture'][0]);
        $this->assertContains('fixture-plugin.view', array_column($grouped['Plugins'], 'value'));
        $this->assertSame('view_plugins', $grouped['Plugins'][0]['value'], 'core permissions stay first');
        $this->assertContains('fixture-plugin.manage', Permission::values());
        $this->assertContains('view_products', Permission::values());
    }

    public function test_admins_hold_registered_plugin_permissions(): void
    {
        $admin = $this->makeAdmin($this->makeOrganization());
        $this->registerFixture();

        $this->assertContains('fixture-plugin.manage', $admin->getAllPermissions());
        $this->assertTrue($admin->hasPermission('fixture-plugin.manage'));
    }

    public function test_a_member_granted_a_plugin_permission_through_a_role_holds_it(): void
    {
        $this->registerFixture();
        $org = $this->makeOrganization();
        $member = $this->makeMember($org);
        $other = $this->makeMember($org);

        $role = Role::create(['name' => 'Fixture users', 'organization_id' => $org->id, 'permissions' => ['fixture-plugin.manage']]);
        $member->roles()->attach($role->id);

        $this->assertTrue($member->fresh()->hasPermission('fixture-plugin.manage'));
        $this->assertTrue(PluginUIService::allows($member->fresh(), 'fixture-plugin.manage'));
        $this->assertFalse(PluginUIService::allows($other, 'fixture-plugin.manage'));
    }

    public function test_the_role_page_lists_a_granted_plugin_permission(): void
    {
        $this->registerFixture();
        $org = $this->makeOrganization();
        $admin = $this->makeAdmin($org);
        $role = Role::create(['name' => 'Fixture users', 'organization_id' => $org->id, 'permissions' => ['fixture-plugin.manage', 'other-plugin.gone']]);

        $this->actingAs($admin)->get(route('roles.show', $role))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rolePermissions', 1)
                ->where('rolePermissions.0.value', 'fixture-plugin.manage')
                ->where('rolePermissions.0.label', 'Manage Fixture')
                ->where('rolePermissions.0.category', 'Fixture'));

        $this->actingAs($admin)->get(route('roles.create'))
            ->assertInertia(fn ($page) => $page->where('permissions.Fixture.0.value', 'fixture-plugin.manage'));
    }

    public function test_api_tokens_accept_registered_plugin_abilities_only(): void
    {
        $admin = $this->makeAdmin($this->makeOrganization());

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tokens', ['name' => 'early', 'abilities' => ['fixture-plugin.manage']])
            ->assertUnprocessable();

        $this->registerFixture();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tokens', ['name' => 'plugin', 'abilities' => ['fixture-plugin.manage']])
            ->assertCreated()
            ->assertJsonPath('abilities', ['fixture-plugin.manage']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tokens', ['name' => 'typo', 'abilities' => ['fixture-plugin.manages']])
            ->assertUnprocessable();
    }

    public function test_permission_sets_accept_registered_plugin_permissions_only(): void
    {
        $admin = $this->makeAdmin($this->makeOrganization());
        $body = ['name' => 'Fixture set', 'permissions' => ['view_products', 'fixture-plugin.manage']];

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/permission-sets', $body)->assertUnprocessable();

        $this->registerFixture();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/permission-sets', $body)->assertCreated();
        $this->assertSame(['view_products', 'fixture-plugin.manage'], PermissionSet::where('name', 'Fixture set')->value('permissions'));
    }

    public function test_deleting_a_plugin_strips_its_permissions_from_roles_and_sets(): void
    {
        $slug = 'perm-fixture';
        $dir = base_path("plugins/{$slug}");
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/plugin.json", json_encode(['name' => 'Perm fixture', 'version' => '1.0.0', 'author' => 'Tests', 'description' => 'x']));
        File::put("{$dir}/Plugin.php", "<?php\n");

        try {
            $org = $this->makeOrganization();
            $role = Role::create(['name' => 'Mixed', 'organization_id' => $org->id,
                'permissions' => ['view_products', "{$slug}.manage", 'perm-fixture-two.manage']]);
            $set = PermissionSet::create(['name' => 'Mixed set', 'organization_id' => $org->id,
                'permissions' => ["{$slug}.view", 'view_orders']]);

            app(PluginService::class)->deletePlugin($slug);

            $this->assertSame(['view_products', 'perm-fixture-two.manage'], $role->fresh()->permissions);
            $this->assertSame(['view_orders'], $set->fresh()->permissions);
            $this->assertDirectoryDoesNotExist($dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
