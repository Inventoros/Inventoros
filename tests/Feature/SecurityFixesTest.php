<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Order\Order;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;
    protected Organization $orgB;
    protected User $userA;
    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->orgA = Organization::create([
            'name' => 'Organization A',
            'email' => 'a@test.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->orgB = Organization::create([
            'name' => 'Organization B',
            'email' => 'b@test.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->userA = User::create([
            'name' => 'User A',
            'email' => 'usera@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->orgA->id,
            'role' => 'admin',
        ]);

        $this->userB = User::create([
            'name' => 'User B',
            'email' => 'userb@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->orgB->id,
            'role' => 'admin',
        ]);

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['manage_organization'],
                'organization_id' => null,
            ]
        );
        Role::firstOrCreate(
            ['slug' => 'system-manager'],
            [
                'name' => 'Manager',
                'is_system' => true,
                'permissions' => ['manage_organization'],
                'organization_id' => null,
            ]
        );
        Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'is_system' => true,
                'permissions' => [],
                'organization_id' => null,
            ]
        );
    }

    protected function createWebhook(Organization $org, array $attrs = []): Webhook
    {
        return Webhook::create(array_merge([
            'organization_id' => $org->id,
            'name' => 'Test Webhook',
            'url' => 'https://example.com/hook',
            'secret' => 'test-secret',
            'events' => ['order.created'],
            'is_active' => true,
        ], $attrs));
    }

    // =========================================================================
    // 1. WebhookDelivery Tenant Isolation
    // =========================================================================

    public function test_webhook_delivery_has_organization_id_column(): void
    {
        $webhook = $this->createWebhook($this->orgA);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'organization_id' => $this->orgA->id,
            'event' => 'order.created',
            'payload' => ['test' => 'data'],
            'status' => 'pending',
        ]);

        $this->assertEquals($this->orgA->id, $delivery->organization_id);
    }

    public function test_webhook_delivery_for_organization_scope_filters_by_org(): void
    {
        $webhookA = $this->createWebhook($this->orgA, ['url' => 'https://a.com/hook']);
        $webhookB = $this->createWebhook($this->orgB, ['url' => 'https://b.com/hook']);

        WebhookDelivery::create([
            'webhook_id' => $webhookA->id,
            'organization_id' => $this->orgA->id,
            'event' => 'order.created',
            'payload' => ['org' => 'A'],
            'status' => 'success',
        ]);

        WebhookDelivery::create([
            'webhook_id' => $webhookB->id,
            'organization_id' => $this->orgB->id,
            'event' => 'order.created',
            'payload' => ['org' => 'B'],
            'status' => 'success',
        ]);

        $deliveriesA = WebhookDelivery::forOrganization($this->orgA->id)->get();
        $deliveriesB = WebhookDelivery::forOrganization($this->orgB->id)->get();

        $this->assertCount(1, $deliveriesA);
        $this->assertCount(1, $deliveriesB);
        $this->assertEquals($this->orgA->id, $deliveriesA->first()->organization_id);
        $this->assertEquals($this->orgB->id, $deliveriesB->first()->organization_id);
    }

    public function test_webhook_delivery_cannot_be_accessed_by_other_org_via_scope(): void
    {
        $webhookA = $this->createWebhook($this->orgA);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhookA->id,
            'organization_id' => $this->orgA->id,
            'event' => 'order.created',
            'payload' => ['test' => true],
            'status' => 'failed',
        ]);

        // Org B should not see org A's deliveries via forOrganization scope
        $orgBDeliveries = WebhookDelivery::forOrganization($this->orgB->id)->get();
        $this->assertCount(0, $orgBDeliveries);

        // The delivery's webhook belongs to org A, verify org check
        $this->assertNotEquals($this->orgB->id, $delivery->webhook->organization_id);

        // Org A should see it
        $orgADeliveries = WebhookDelivery::forOrganization($this->orgA->id)->get();
        $this->assertCount(1, $orgADeliveries);
        $this->assertEquals($delivery->id, $orgADeliveries->first()->id);
    }

    // =========================================================================
    // 2. Order Number Generation Scoping
    // =========================================================================

    public function test_order_number_generation_is_scoped_by_organization(): void
    {
        // Create an order for org A
        $orderA = Order::create([
            'organization_id' => $this->orgA->id,
            'order_number' => Order::generateOrderNumber($this->orgA->id),
            'status' => 'pending',
            'total' => 100,
            'currency' => 'USD',
        ]);

        // Create an order for org B - should get the same sequence number
        $orderB = Order::create([
            'organization_id' => $this->orgB->id,
            'order_number' => Order::generateOrderNumber($this->orgB->id),
            'status' => 'pending',
            'total' => 200,
            'currency' => 'USD',
        ]);

        // Both should be 0001 since they're in different orgs
        $this->assertStringEndsWith('-0001', $orderA->order_number);
        $this->assertStringEndsWith('-0001', $orderB->order_number);
    }

    public function test_order_number_increments_within_same_organization(): void
    {
        $order1 = Order::create([
            'organization_id' => $this->orgA->id,
            'order_number' => Order::generateOrderNumber($this->orgA->id),
            'status' => 'pending',
            'total' => 100,
            'currency' => 'USD',
        ]);

        $order2 = Order::create([
            'organization_id' => $this->orgA->id,
            'order_number' => Order::generateOrderNumber($this->orgA->id),
            'status' => 'pending',
            'total' => 200,
            'currency' => 'USD',
        ]);

        $this->assertStringEndsWith('-0001', $order1->order_number);
        $this->assertStringEndsWith('-0002', $order2->order_number);
    }

    // =========================================================================
    // 3. Security Headers Middleware
    // =========================================================================

    protected function getResponseThroughSecurityHeaders(): Response
    {
        $middleware = new SecurityHeaders();
        $request = Request::create('/test', 'GET');

        return $middleware->handle($request, function () {
            return new Response('OK', 200);
        });
    }

    public function test_response_contains_x_frame_options_header(): void
    {
        $response = $this->getResponseThroughSecurityHeaders();
        $this->assertEquals('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function test_response_contains_x_content_type_options_header(): void
    {
        $response = $this->getResponseThroughSecurityHeaders();
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_response_contains_referrer_policy_header(): void
    {
        $response = $this->getResponseThroughSecurityHeaders();
        $this->assertEquals('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    public function test_response_contains_permissions_policy_header(): void
    {
        $response = $this->getResponseThroughSecurityHeaders();
        $this->assertNotNull($response->headers->get('Permissions-Policy'));
    }

    public function test_response_contains_x_xss_protection_header(): void
    {
        $response = $this->getResponseThroughSecurityHeaders();
        $this->assertEquals('1; mode=block', $response->headers->get('X-XSS-Protection'));
    }

    // =========================================================================
    // 4. API Rate Limiting
    // =========================================================================

    public function test_api_routes_have_rate_limiting(): void
    {
        Sanctum::actingAs($this->userA, ['*']);

        $response = $this->getJson('/api/v1/products');

        $response->assertHeader('X-RateLimit-Limit');
        $response->assertHeader('X-RateLimit-Remaining');
    }

    public function test_api_rate_limit_returns_429_when_exceeded(): void
    {
        Sanctum::actingAs($this->userA, ['*']);

        // Exhaust the rate limit (60 requests per minute)
        for ($i = 0; $i < 61; $i++) {
            $response = $this->getJson('/api/v1/user');
        }

        $response->assertStatus(429);
    }
}
