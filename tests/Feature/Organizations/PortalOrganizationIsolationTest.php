<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\OrderStatus;
use App\Services\Organizations\OrganizationMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Portal\BuildsPortalFixtures;
use Tests\TestCase;

/**
 * The customer portal resolves its organization from the URL and its user
 * from the customer guard. A staff user switching organization in the same
 * browser session changes neither: a contact sees their own organization's
 * portal only, and the staff side keeps its own active organization.
 */
final class PortalOrganizationIsolationTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
    }

    public function test_a_staff_switch_in_the_same_session_does_not_reach_the_portal(): void
    {
        $alpha = $this->makeOrganization('Alpha Wholesale');
        $beta = $this->makeOrganization('Beta Wholesale');
        $alphaCustomer = $this->makeCustomer($alpha, 'Alpha Buyer');
        $betaCustomer = $this->makeCustomer($beta, 'Beta Buyer');
        $contact = $this->makeContact($alphaCustomer, 'buyer@alpha.test');
        $this->makeOrder($alphaCustomer, 'ORD-ALPHA', OrderStatus::PROCESSING);
        $this->makeOrder($betaCustomer, 'ORD-BETA', OrderStatus::PROCESSING);

        $staff = $this->makeStaff($alpha, 'staff@alpha.test');
        $this->makeStaff($beta, 'owner@beta.test');
        app(OrganizationMembershipService::class)->add($beta, $staff, 'admin');

        // Staff signs in and switches to Beta.
        $this->post('/login', ['email' => 'staff@alpha.test', 'password' => 'password'])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->post(route('organizations.switch'), ['organization_id' => $beta->id])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->withCookie(config('session.cookie'), session()->getId());

        // The contact signs in to Alpha's portal in the same browser.
        $this->post($this->portalUrl($alpha, 'login'), ['email' => 'buyer@alpha.test', 'password' => 'secret-password'])
            ->assertRedirect($this->portalUrl($alpha));
        $this->app['auth']->forgetGuards();
        $this->withCookie(config('session.cookie'), session()->getId());

        $this->get($this->portalUrl($alpha, 'orders'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.order_number', 'ORD-ALPHA'));
        $this->app['auth']->forgetGuards();

        // Beta's portal does not take Alpha's contact.
        $this->get($this->portalUrl($beta, 'orders'))->assertRedirect();
        $this->app['auth']->forgetGuards();

        // The staff side is still in Beta.
        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('auth.organization.id', $beta->id));

        $this->assertSame($alpha->id, $contact->fresh()->organization_id);
    }
}
