<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $viewOnlyUser;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->viewOnlyUser = User::create([
            'name' => 'View Only User',
            'email' => 'viewer@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => [
                    'view_customers', 'create_customers', 'edit_customers', 'delete_customers',
                ],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => ['view_customers'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    protected function createCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'Test Customer',
            'email' => 'customer@test.com',
            'phone' => '555-1234',
            'is_active' => true,
        ], $attributes));
    }

    public function test_admin_can_view_customers_list(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('customers.index'));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_create_customer_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('customers.create'));

        $response->assertStatus(200);
    }

    public function test_admin_can_create_customer(): void
    {
        $customerData = [
            'name' => 'New Customer',
            'email' => 'newcustomer@test.com',
            'phone' => '555-9999',
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('customers.store'), $customerData);

        $response->assertRedirect(route('customers.index'));

        $this->assertDatabaseHas('customers', [
            'name' => 'New Customer',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_admin_can_view_customer(): void
    {
        $customer = $this->createCustomer();

        $response = $this->actingAs($this->admin)
            ->get(route('customers.show', $customer));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_edit_customer_form(): void
    {
        $customer = $this->createCustomer();

        $response = $this->actingAs($this->admin)
            ->get(route('customers.edit', $customer));

        $response->assertStatus(200);
    }

    public function test_admin_can_update_customer(): void
    {
        $customer = $this->createCustomer();

        $response = $this->actingAs($this->admin)
            ->put(route('customers.update', $customer), [
                'name' => 'Updated Customer',
                'email' => $customer->email,
            ]);

        $response->assertRedirect(route('customers.index'));

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Updated Customer',
        ]);
    }

    public function test_admin_can_delete_customer(): void
    {
        $customer = $this->createCustomer();

        $response = $this->actingAs($this->admin)
            ->delete(route('customers.destroy', $customer));

        $response->assertRedirect(route('customers.index'));

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_view_only_user_cannot_create_customer(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('customers.create'));

        $response->assertStatus(403);
    }

    public function test_view_only_user_cannot_delete_customer(): void
    {
        $customer = $this->createCustomer();

        $response = $this->actingAs($this->viewOnlyUser)
            ->delete(route('customers.destroy', $customer));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_view_customers_list(): void
    {
        $response = $this->get(route('customers.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_customer_creation_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('customers.store'), [
                'name' => '',
            ]);

        $response->assertSessionHasErrors(['name']);
    }
}
