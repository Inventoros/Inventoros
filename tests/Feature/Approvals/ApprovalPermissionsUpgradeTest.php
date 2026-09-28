<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\PermissionSet;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Installs seeded before approvals existed have a Manager role without the
 * approve_* permissions and no "Approver" template. The upgrade migration
 * brings them in line with RoleSeeder and PermissionSet::getDefaultTemplates().
 */
class ApprovalPermissionsUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_28_092045_grant_approval_permissions_to_existing_roles.php';

    private const APPROVE = ['approve_purchase_orders', 'approve_stock_adjustments', 'approve_stock_transfers'];

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    public function test_it_grants_the_approve_permissions_to_existing_system_manager_and_administrator_roles(): void
    {
        Role::whereIn('slug', ['system-manager', 'system-administrator', 'system-member'])->delete();
        $manager = Role::create(['slug' => 'system-manager', 'name' => 'Manager', 'is_system' => true, 'permissions' => ['view_orders']]);
        $administrator = Role::create(['slug' => 'system-administrator', 'name' => 'Administrator', 'is_system' => true, 'permissions' => ['view_orders']]);
        $member = Role::create(['slug' => 'system-member', 'name' => 'Member', 'is_system' => true, 'permissions' => ['view_orders']]);
        $custom = Role::create(['slug' => 'custom-buyer', 'name' => 'Buyer', 'is_system' => false, 'permissions' => ['view_orders']]);

        $migration = $this->migration();
        $migration->up();
        $migration->up(); // idempotent

        $this->assertSame(array_merge(['view_orders'], self::APPROVE), $manager->fresh()->permissions);
        $this->assertSame(array_merge(['view_orders'], self::APPROVE), $administrator->fresh()->permissions);
        $this->assertSame(['view_orders'], $member->fresh()->permissions);
        $this->assertSame(['view_orders'], $custom->fresh()->permissions);

        $migration->down();
        $this->assertSame(['view_orders'], $manager->fresh()->permissions);
        $this->assertSame(['view_orders'], $administrator->fresh()->permissions);
    }

    public function test_it_adds_the_approver_template_to_installs_that_have_the_default_templates(): void
    {
        PermissionSet::create([
            'name' => 'Order Processor', 'slug' => 'order-processor', 'category' => 'orders',
            'permissions' => ['view_orders'], 'is_template' => true, 'position' => 3,
        ]);

        $migration = $this->migration();
        $migration->up();
        $migration->up(); // idempotent

        $approvers = PermissionSet::where('slug', 'approver')->get();
        $this->assertCount(1, $approvers);

        $expected = collect(PermissionSet::getDefaultTemplates())->firstWhere('slug', 'approver');
        $approver = $approvers->first();
        $this->assertTrue($approver->is_template);
        $this->assertTrue($approver->is_active);
        $this->assertNull($approver->organization_id);
        $this->assertSame($expected['name'], $approver->name);
        $this->assertSame($expected['permissions'], $approver->permissions);

        $migration->down();
        $this->assertFalse(PermissionSet::where('slug', 'approver')->exists());
    }

    public function test_it_does_not_add_the_template_when_the_install_never_seeded_templates(): void
    {
        $this->migration()->up();

        $this->assertFalse(PermissionSet::where('slug', 'approver')->exists());
    }

    public function test_down_keeps_an_approver_template_that_roles_still_use(): void
    {
        PermissionSet::create([
            'name' => 'Order Processor', 'slug' => 'order-processor', 'category' => 'orders',
            'permissions' => ['view_orders'], 'is_template' => true,
        ]);
        $migration = $this->migration();
        $migration->up();

        $role = Role::create(['slug' => 'custom-approver', 'name' => 'Approver', 'is_system' => false, 'permissions' => []]);
        $role->permissionSets()->attach(PermissionSet::where('slug', 'approver')->value('id'));

        $migration->down();

        $this->assertTrue(PermissionSet::where('slug', 'approver')->exists());
    }
}
