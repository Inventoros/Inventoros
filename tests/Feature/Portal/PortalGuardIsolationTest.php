<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff (web guard) and customer contacts (customer guard) share one browser
 * session but never each other's routes.
 */
class PortalGuardIsolationTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
    }

    public function test_a_signed_in_contact_cannot_reach_staff_routes(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer Ltd');
        $this->makeContact($customer, 'buyer@example.test');
        $order = $this->makeOrder($customer, 'ORD-1');

        // Sign in through the real form so the session carries only the
        // customer guard, exactly as it would in a browser.
        $this->post($this->portalUrl($org, 'login'), [
            'email' => 'buyer@example.test',
            'password' => 'secret-password',
        ])->assertRedirect($this->portalUrl($org));

        $this->get($this->portalUrl($org))->assertOk();

        foreach (['/dashboard', '/orders', '/orders/'.$order->id, '/customers', '/returns', '/settings'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }

        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_a_signed_in_staff_member_cannot_reach_portal_routes(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $staff = $this->makeStaff($org, 'admin@example.test');
        $customer = $this->makeCustomer($org, 'Buyer Ltd');
        $order = $this->makeOrder($customer, 'ORD-1');

        $this->actingAs($staff);

        $this->get($this->portalUrl($org))->assertRedirect($this->portalUrl($org, 'login'));
        $this->get($this->portalUrl($org, 'orders/'.$order->id))->assertRedirect($this->portalUrl($org, 'login'));
        $this->get($this->portalUrl($org, 'orders/'.$order->id.'/invoice'))->assertRedirect($this->portalUrl($org, 'login'));
        $this->assertGuest('customer');
    }

    public function test_both_sessions_coexist_without_leaking_into_each_other(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');

        // Staff member of org B and a contact of org A in the same browser.
        $staffB = $this->makeStaff($orgB, 'admin@b.test');
        $customerA = $this->makeCustomer($orgA, 'Buyer A');
        $this->makeContact($customerA, 'buyer@a.test');
        $orderA = $this->makeOrder($customerA, 'ORD-A-1');
        $customerB = $this->makeCustomer($orgB, 'Buyer B');
        $orderB = $this->makeOrder($customerB, 'ORD-B-1');

        $this->post('/login', ['email' => 'admin@b.test', 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->post($this->portalUrl($orgA, 'login'), [
            'email' => 'buyer@a.test',
            'password' => 'secret-password',
        ])->assertRedirect($this->portalUrl($orgA));

        // The portal sees org A's order even though the staff session is org B:
        // the staff OrganizationScope must not be what scopes portal queries.
        $this->get($this->portalUrl($orgA, 'orders/'.$orderA->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order.order_number', 'ORD-A-1')
                ->missing('auth.user.email'));

        // ...and never org B's.
        $this->get($this->portalUrl($orgA, 'orders/'.$orderB->id))->assertNotFound();

        // The staff session still works and is still org B only.
        $this->get('/orders/'.$orderB->id)->assertOk();
        $this->get('/orders/'.$orderA->id)->assertNotFound();
        $this->assertAuthenticatedAs($staffB, 'web');
    }

    public function test_portal_pages_do_not_share_staff_props(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $staff = $this->makeStaff($org, 'admin@example.test');
        $customer = $this->makeCustomer($org, 'Buyer Ltd');
        $contact = $this->makeContact($customer, 'buyer@example.test');
        $this->makeOrder($customer, 'ORD-1', OrderStatus::PROCESSING);

        $this->actingAs($staff, 'web')
            ->actingAs($contact, 'customer')
            ->get($this->portalUrl($org))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Dashboard')
                ->where('auth.user', null)
                ->where('portal.contact.email', 'buyer@example.test')
                ->missing('warehouses')
                ->missing('pluginMenuItems'));
    }

    public function test_activity_logging_never_attributes_a_contact_as_a_staff_user(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer Ltd');
        $contact = $this->makeContact($customer, 'buyer@example.test');

        $this->actingAs($contact, 'customer');

        // With the customer guard as the default, the automatic model
        // activity log must not write the contact's id into users.user_id.
        $this->assertNull(ActivityLog::log('updated', $customer, 'x'));
        $customer->update(['phone' => '555-0100']);
        $this->assertSame(0, ActivityLog::where('subject_type', Customer::class)->count());
    }
}
