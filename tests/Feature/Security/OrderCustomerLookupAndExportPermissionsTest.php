<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\PermissionSet;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Database\Seeders\PermissionSetSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The order form's customer lookup returns customer contact details and
 * addresses, and the order/product exports return whole tables, so each
 * needs the permission that covers the data it returns, not only
 * view_orders or export_data.
 */
class OrderCustomerLookupAndExportPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Lookup Org', 'email' => 'lookup@org.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->otherOrganization = Organization::create([
            'name' => 'Other Org', 'email' => 'other@org.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        Customer::factory()->create([
            'organization_id' => $this->organization->id, 'name' => 'Acme Retail', 'email' => 'buy@acme.test',
        ]);
        Customer::factory()->create([
            'organization_id' => $this->otherOrganization->id, 'name' => 'Acme Foreign', 'email' => 'foreign@acme.test',
        ]);
    }

    /**
     * A user whose only grants are $permissions, through an organization role
     * (no system-member row exists here, so the base role grants nothing).
     *
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        static $sequence = 0;
        $sequence++;

        $user = User::create([
            'name' => "User {$sequence}",
            'email' => "user{$sequence}@org.test",
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $role = Role::create([
            'name' => "Role {$sequence}",
            'organization_id' => $this->organization->id,
            'permissions' => $permissions,
            'is_system' => false,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_customer_lookup_is_refused_with_view_orders_alone(): void
    {
        $this->actingAs($this->userWith(['view_orders']))
            ->getJson(route('orders.customer-lookup', ['q' => 'acme']))
            ->assertForbidden();
    }

    public function test_customer_lookup_is_allowed_to_order_editors_and_customer_viewers(): void
    {
        foreach ([['create_orders'], ['edit_orders'], ['view_customers']] as $permissions) {
            $response = $this->actingAs($this->userWith($permissions))
                ->getJson(route('orders.customer-lookup', ['q' => 'acme']))
                ->assertOk();

            // Still scoped to the user's own organization.
            $this->assertSame(['Acme Retail'], collect($response->json('customers'))->pluck('name')->all());
        }
    }

    public function test_order_export_needs_view_orders_as_well_as_export_data(): void
    {
        $this->actingAs($this->userWith(['export_data']))
            ->get(route('import-export.export-orders'))
            ->assertForbidden();

        $this->actingAs($this->userWith(['view_orders']))
            ->get(route('import-export.export-orders'))
            ->assertForbidden();

        $this->actingAs($this->userWith(['export_data', 'view_orders']))
            ->get(route('import-export.export-orders'))
            ->assertOk();
    }

    public function test_product_export_needs_view_products_as_well_as_export_data(): void
    {
        $this->actingAs($this->userWith(['export_data']))
            ->get(route('import-export.export-products'))
            ->assertForbidden();

        $this->actingAs($this->userWith(['export_data', 'view_products']))
            ->get(route('import-export.export-products'))
            ->assertOk();
    }

    public function test_default_roles_and_templates_keep_their_intended_access(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSetSeeder::class);

        // The base Manager role creates orders and exports both tables.
        $manager = User::create([
            'name' => 'Manager', 'email' => 'manager@org.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'manager',
        ]);
        $this->actingAs($manager)->getJson(route('orders.customer-lookup'))->assertOk();
        $this->actingAs($manager)->get(route('import-export.export-orders'))->assertOk();
        $this->actingAs($manager)->get(route('import-export.export-products'))->assertOk();

        // The base Member role views orders but does not see the customer list.
        $member = User::create([
            'name' => 'Member', 'email' => 'member@org.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'member',
        ]);
        $this->actingAs($member)->getJson(route('orders.customer-lookup'))->assertForbidden();

        // The Order Processor template creates and edits orders.
        $processor = $this->userWith(
            PermissionSet::where('slug', 'order-processor')->firstOrFail()->permissions
        );
        $this->actingAs($processor)->getJson(route('orders.customer-lookup'))->assertOk();

        // The Reports Viewer template exports orders and products.
        $reports = $this->userWith(
            PermissionSet::where('slug', 'reports-viewer')->firstOrFail()->permissions
        );
        $this->actingAs($reports)->get(route('import-export.export-orders'))->assertOk();
        $this->actingAs($reports)->get(route('import-export.export-products'))->assertOk();
    }
}
