<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Auth\Organization;
use App\Models\PermissionSet;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * view_payments and record_payments are real permissions, granted the same
 * way as the order permissions they sit beside.
 */
class PaymentPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_permissions_exist_under_their_own_category(): void
    {
        $this->assertSame('view_payments', Permission::VIEW_PAYMENTS->value);
        $this->assertSame('record_payments', Permission::RECORD_PAYMENTS->value);
        $this->assertSame('Payments', Permission::VIEW_PAYMENTS->category());
        $this->assertArrayHasKey('Payments', Permission::grouped());
    }

    public function test_admins_hold_them_implicitly(): void
    {
        $org = Organization::create(['name' => 'P', 'email' => 'p@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $admin = User::create([
            'name' => 'A', 'email' => 'a@p.test', 'password' => bcrypt('x'), 'organization_id' => $org->id, 'role' => 'admin',
        ]);

        $this->assertTrue($admin->hasPermission(Permission::VIEW_PAYMENTS));
        $this->assertTrue($admin->hasPermission(Permission::RECORD_PAYMENTS));
    }

    public function test_seeded_roles_grant_them_consistently(): void
    {
        $this->seed(RoleSeeder::class);

        $administrator = Role::where('slug', 'system-administrator')->firstOrFail();
        $manager = Role::where('slug', 'system-manager')->firstOrFail();
        $member = Role::where('slug', 'system-member')->firstOrFail();

        $this->assertTrue($administrator->hasPermission('view_payments'));
        $this->assertTrue($administrator->hasPermission('record_payments'));
        $this->assertTrue($manager->hasPermission('view_payments'));
        $this->assertTrue($manager->hasPermission('record_payments'));
        $this->assertFalse($member->hasPermission('view_payments'));
        $this->assertFalse($member->hasPermission('record_payments'));
    }

    public function test_permission_set_templates(): void
    {
        $templates = collect(PermissionSet::getDefaultTemplates())->keyBy('slug');

        $this->assertContains('record_payments', $templates['order-processor']['permissions']);
        $this->assertContains('view_payments', $templates['order-processor']['permissions']);
        // Viewers of financial data can see payments but never record them.
        $this->assertContains('view_payments', $templates['read-only-auditor']['permissions']);
        $this->assertNotContains('record_payments', $templates['read-only-auditor']['permissions']);
        $this->assertContains('view_payments', $templates['reports-viewer']['permissions']);
        $this->assertNotContains('record_payments', $templates['reports-viewer']['permissions']);
        // Warehouse staff handle goods, not money.
        $this->assertNotContains('view_payments', $templates['warehouse-staff']['permissions']);
    }

    public function test_the_migration_grants_them_to_an_existing_system_manager_role(): void
    {
        Role::where('slug', 'system-manager')->delete();
        $manager = Role::create([
            'slug' => 'system-manager', 'name' => 'Manager', 'is_system' => true,
            'permissions' => ['view_orders', 'edit_orders'],
        ]);

        $migration = require database_path('migrations/2026_09_30_000003_grant_payment_permissions_to_system_manager.php');
        $migration->up();
        $migration->up(); // idempotent

        $permissions = $manager->fresh()->permissions;
        $this->assertSame(['view_orders', 'edit_orders', 'view_payments', 'record_payments'], $permissions);

        $migration->down();
        $this->assertSame(['view_orders', 'edit_orders'], $manager->fresh()->permissions);
    }

    public function test_the_migration_grants_them_to_existing_permission_set_templates(): void
    {
        $make = fn (string $slug) => PermissionSet::create([
            'name' => $slug, 'slug' => $slug, 'category' => 'orders', 'is_template' => true,
            'permissions' => ['view_orders'],
        ]);
        $processor = $make('order-processor');
        $auditor = $make('read-only-auditor');
        $viewer = $make('reports-viewer');
        $staff = $make('warehouse-staff');

        $migration = require database_path('migrations/2026_09_30_000003_grant_payment_permissions_to_system_manager.php');
        $migration->up();

        $this->assertSame(['view_orders', 'view_payments', 'record_payments'], $processor->fresh()->permissions);
        $this->assertSame(['view_orders', 'view_payments'], $auditor->fresh()->permissions);
        $this->assertSame(['view_orders', 'view_payments'], $viewer->fresh()->permissions);
        $this->assertSame(['view_orders'], $staff->fresh()->permissions);

        $migration->down();
        $this->assertSame(['view_orders'], $processor->fresh()->permissions);
        $this->assertSame(['view_orders'], $auditor->fresh()->permissions);
    }
}
