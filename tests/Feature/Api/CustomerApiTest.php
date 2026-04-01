<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
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

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['view_customers', 'manage_customers', 'create_customers', 'edit_customers', 'delete_customers'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    protected function createCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'Test Customer',
            'email' => 'customer' . uniqid() . '@test.com',
            'phone' => '555-1234',
            'is_active' => true,
        ], $attributes));
    }

    public function test_can_create_customer(): void
    {
        $this->actingAs($this->admin);

        $customerData = [
            'name' => 'New Customer',
            'email' => 'newcustomer@test.com',
            'phone' => '555-9999',
        ];

        $response = $this->postJson('/customers', $customerData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('customers', [
            'name' => 'New Customer',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_can_update_customer(): void
    {
        $this->actingAs($this->admin);

        $customer = $this->createCustomer();

        $response = $this->putJson("/customers/{$customer->id}", [
            'name' => 'Updated Customer',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Updated Customer',
        ]);
    }

    public function test_customer_model_can_be_created(): void
    {
        $customer = $this->createCustomer(['name' => 'Model Customer']);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Model Customer',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_customer_model_can_be_soft_deleted(): void
    {
        $customer = $this->createCustomer();

        $customer->delete();

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_create_customer_validates_required_fields(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/customers', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_customer_belongs_to_organization(): void
    {
        $customer = $this->createCustomer();

        $this->assertEquals($this->organization->id, $customer->organization_id);
    }

    public function test_unauthenticated_cannot_access_customers(): void
    {
        $response = $this->postJson('/customers', ['name' => 'Test']);

        $response->assertStatus(401);
    }
}
