<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Permission;
use App\Models\PermissionSet;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * view_shipments and create_shipments are real permissions, grouped under
 * Shipping, and granted to the roles whose job includes fulfilment.
 */
class ShippingPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipment_permissions_exist_with_labels_and_a_shipping_category(): void
    {
        $this->assertSame('view_shipments', Permission::VIEW_SHIPMENTS->value);
        $this->assertSame('create_shipments', Permission::CREATE_SHIPMENTS->value);
        $this->assertSame('Shipping', Permission::VIEW_SHIPMENTS->category());
        $this->assertSame('Shipping', Permission::CREATE_SHIPMENTS->category());
        $this->assertSame('View Shipments', Permission::VIEW_SHIPMENTS->label());
        $this->assertNotEmpty(Permission::CREATE_SHIPMENTS->description());
        $this->assertArrayHasKey('Shipping', Permission::grouped());
    }

    public function test_seeded_roles_grant_shipment_permissions_to_fulfilment_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = Role::where('slug', 'system-administrator')->first()->permissions;
        $manager = Role::where('slug', 'system-manager')->first()->permissions;
        $member = Role::where('slug', 'system-member')->first()->permissions;

        $this->assertContains('view_shipments', $admin);
        $this->assertContains('create_shipments', $admin);
        $this->assertContains('view_shipments', $manager);
        $this->assertContains('create_shipments', $manager);
        $this->assertNotContains('create_shipments', $member);
    }

    public function test_permission_set_templates_grant_shipment_permissions(): void
    {
        $templates = collect(PermissionSet::getDefaultTemplates())->keyBy('slug');

        $this->assertContains('create_shipments', $templates['order-processor']['permissions']);
        $this->assertContains('view_shipments', $templates['order-processor']['permissions']);
        $this->assertContains('create_shipments', $templates['warehouse-staff']['permissions']);
        $this->assertContains('view_shipments', $templates['read-only-auditor']['permissions']);
        $this->assertNotContains('create_shipments', $templates['read-only-auditor']['permissions']);
    }

    public function test_upgrade_migration_grants_existing_roles(): void
    {
        DB::table('roles')->where('slug', 'system-manager')->delete();
        DB::table('roles')->insert([
            'slug' => 'system-manager', 'name' => 'Manager', 'is_system' => true,
            'permissions' => json_encode(['view_orders']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_27_150549_grant_shipment_permissions_to_fulfilment_roles.php');
        $migration->up();

        $permissions = json_decode((string) DB::table('roles')->where('slug', 'system-manager')->value('permissions'), true);
        $this->assertContains('view_shipments', $permissions);
        $this->assertContains('create_shipments', $permissions);

        // Idempotent: a second run does not duplicate.
        $migration->up();
        $permissions = json_decode((string) DB::table('roles')->where('slug', 'system-manager')->value('permissions'), true);
        $this->assertSame(1, count(array_keys($permissions, 'view_shipments', true)));
    }
}
