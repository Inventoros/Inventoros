<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * REST parity for customers: /api/v1/customers CRUD plus a customer's orders.
 */
class CustomerRestApiTest extends TestCase
{
    use BuildsApiFixtures, RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        $this->org = $this->makeOrganization('Acme');
        $this->admin = $this->makeAdmin($this->org);
    }

    private function customer(Organization $org, array $attributes = []): Customer
    {
        return Customer::withoutGlobalScopes()->create(array_merge([
            'organization_id' => $org->id,
            'name' => 'Customer '.uniqid(),
            'email' => uniqid().'@customer.test',
            'is_active' => true,
        ], $attributes));
    }

    private function order(Customer $customer): Order
    {
        return Order::withoutGlobalScopes()->create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'customer_name' => $customer->name,
            'status' => 'pending',
            'subtotal' => 10,
            'tax' => 0,
            'shipping' => 0,
            'total' => 10,
            'currency' => 'USD',
            'order_date' => now()->toDateString(),
        ]);
    }

    public function test_lists_only_own_organization_customers_paginated(): void
    {
        $this->customer($this->org, ['name' => 'Mine']);
        $this->customer($this->makeOrganization('Other'), ['name' => 'Theirs']);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine')
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'orders_count']], 'links', 'meta']);
    }

    public function test_per_page_is_capped_at_100(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/customers?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_can_create_update_and_delete_a_customer(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/v1/customers', ['name' => 'New Co', 'email' => 'new@co.test'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'New Co')
            ->json('data.id');

        $this->assertDatabaseHas('customers', ['id' => $id, 'organization_id' => $this->org->id]);

        $this->putJson("/api/v1/customers/{$id}", ['name' => 'Renamed Co'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Co');

        $this->deleteJson("/api/v1/customers/{$id}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $id]);
    }

    public function test_cannot_delete_a_customer_with_orders(): void
    {
        $customer = $this->customer($this->org);
        $this->order($customer);

        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/v1/customers/{$customer->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'has_orders');
    }

    public function test_lists_a_customers_orders(): void
    {
        $customer = $this->customer($this->org);
        $this->order($customer);
        $this->order($this->customer($this->org));

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/v1/customers/{$customer->id}/orders")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_id', $customer->id);
    }

    public function test_cross_tenant_customer_is_not_found(): void
    {
        $foreign = $this->customer($this->makeOrganization('Other'));

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/v1/customers/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/v1/customers/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/customers/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/v1/customers/{$foreign->id}/orders")->assertNotFound();

        $this->assertDatabaseHas('customers', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    public function test_permissions_are_enforced_per_verb(): void
    {
        $viewer = $this->makeMember($this->org, ['view_customers']);
        $customer = $this->customer($this->org);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/customers')->assertOk();
        $this->postJson('/api/v1/customers', ['name' => 'x'])->assertForbidden();
        $this->putJson("/api/v1/customers/{$customer->id}", ['name' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertForbidden();
        // A customer's orders also need view_orders.
        $this->getJson("/api/v1/customers/{$customer->id}/orders")->assertForbidden();
    }

    public function test_token_abilities_restrict_writes(): void
    {
        $token = $this->admin->createToken('ro', ['view_customers'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/customers')->assertOk();
        $this->withToken($token)->postJson('/api/v1/customers', ['name' => 'x'])->assertForbidden();
    }
}
