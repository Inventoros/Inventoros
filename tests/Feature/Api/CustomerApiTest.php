<?php

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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
                'permissions' => ['view_customers', 'manage_customers'],
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

    public function test_can_list_customers(): void
    {
        Sanctum::actingAs($this->admin);

        $this->createCustomer(['name' => 'Customer 1']);
        $this->createCustomer(['name' => 'Customer 2']);

        $response = $this->getJson('/api/v1/customers');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_can_create_customer(): void
    {
        Sanctum::actingAs($this->admin);

        $customerData = [
            'name' => 'New Customer',
            'email' => 'newcustomer@test.com',
            'phone' => '555-9999',
        ];

        $response = $this->postJson('/api/v1/customers', $customerData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('customers', [
            'name' => 'New Customer',
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_can_view_customer(): void
    {
        Sanctum::actingAs($this->admin);

        $customer = $this->createCustomer();

        $response = $this->getJson("/api/v1/customers/{$customer->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $customer->id);
    }

    public function test_can_update_customer(): void
    {
        Sanctum::actingAs($this->admin);

        $customer = $this->createCustomer();

        $response = $this->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'Updated Customer',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Updated Customer',
        ]);
    }

    public function test_can_delete_customer(): void
    {
        Sanctum::actingAs($this->admin);

        $customer = $this->createCustomer();

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_create_customer_validates_required_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/customers', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_unauthenticated_cannot_list_customers(): void
    {
        $response = $this->getJson('/api/v1/customers');

        $response->assertStatus(401);
    }
}
