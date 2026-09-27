<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\SecurityEvent;
use App\Models\ActivityLog;
use App\Models\CustomerContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAuthenticationTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
    }

    public function test_login_page_renders_for_an_enabled_portal(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');

        $this->get($this->portalUrl($org, 'login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Auth/Login')
                ->where('portal.organization.name', 'Acme Wholesale'));
    }

    public function test_portal_is_404_when_disabled_or_unknown(): void
    {
        $disabled = $this->makeOrganization('Closed Co', portalEnabled: false);
        $contact = $this->makeContact($this->makeCustomer($disabled, 'Buyer'), 'buyer@example.test');

        $this->get($this->portalUrl($disabled, 'login'))->assertNotFound();
        $this->get('/portal/no-such-org/login')->assertNotFound();

        $this->post($this->portalUrl($disabled, 'login'), [
            'email' => 'buyer@example.test',
            'password' => 'secret-password',
        ])->assertNotFound();

        // An existing contact session does not survive the portal being switched off.
        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($disabled))
            ->assertNotFound();
    }

    public function test_contact_can_sign_in_and_it_is_logged(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $contact = $this->makeContact($this->makeCustomer($org, 'Buyer Ltd'), 'buyer@example.test');

        $response = $this->post($this->portalUrl($org, 'login'), [
            'email' => 'buyer@example.test',
            'password' => 'secret-password',
        ]);

        $response->assertRedirect($this->portalUrl($org));
        $this->assertAuthenticatedAs($contact, 'customer');
        $this->assertGuest('web');
        $this->assertNotNull($contact->fresh()->last_login_at);

        $log = ActivityLog::where('action', SecurityEvent::PORTAL_LOGIN->value)->first();
        $this->assertNotNull($log);
        $this->assertSame($org->id, $log->organization_id);
        $this->assertNull($log->user_id);
        $this->assertSame(CustomerContact::class, $log->subject_type);
        $this->assertSame($contact->id, $log->subject_id);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $this->makeContact($this->makeCustomer($org, 'Buyer Ltd'), 'buyer@example.test');

        $this->post($this->portalUrl($org, 'login'), [
            'email' => 'buyer@example.test',
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('customer');
    }

    public function test_contact_cannot_sign_in_to_another_organizations_portal(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $this->makeContact($this->makeCustomer($orgA, 'Buyer'), 'buyer@example.test');

        $this->post($this->portalUrl($orgB, 'login'), [
            'email' => 'buyer@example.test',
            'password' => 'secret-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('customer');
    }

    public function test_revoked_uninvited_and_inactive_customer_contacts_cannot_sign_in(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer Ltd');
        $this->makeContact($customer, 'revoked@example.test', attributes: ['revoked_at' => now()]);
        $this->makeContact($customer, 'pending@example.test', password: '');
        $inactiveCustomer = $this->makeCustomer($org, 'Dormant Ltd', ['is_active' => false]);
        $this->makeContact($inactiveCustomer, 'dormant@example.test');

        foreach (['revoked@example.test', 'pending@example.test', 'dormant@example.test'] as $email) {
            $this->post($this->portalUrl($org, 'login'), [
                'email' => $email,
                'password' => 'secret-password',
            ])->assertSessionHasErrors('email');

            $this->assertGuest('customer');
        }
    }

    public function test_login_is_throttled(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $this->makeContact($this->makeCustomer($org, 'Buyer Ltd'), 'buyer@example.test');

        for ($i = 0; $i < 5; $i++) {
            $this->post($this->portalUrl($org, 'login'), [
                'email' => 'buyer@example.test',
                'password' => 'wrong',
            ]);
        }

        $response = $this->post($this->portalUrl($org, 'login'), [
            'email' => 'buyer@example.test',
            'password' => 'secret-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many', session('errors')->first('email'));
        $this->assertGuest('customer');
    }

    public function test_guest_is_redirected_to_the_portal_login(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');

        $this->get($this->portalUrl($org))->assertRedirect($this->portalUrl($org, 'login'));
        $this->get($this->portalUrl($org, 'orders'))->assertRedirect($this->portalUrl($org, 'login'));
    }

    public function test_signed_in_contact_visiting_login_goes_to_dashboard(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $contact = $this->makeContact($this->makeCustomer($org, 'Buyer Ltd'), 'buyer@example.test');

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($org, 'login'))
            ->assertRedirect($this->portalUrl($org));
    }

    public function test_revoking_a_contact_ends_their_active_session(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $contact = $this->makeContact($this->makeCustomer($org, 'Buyer Ltd'), 'buyer@example.test');

        $this->actingAs($contact, 'customer')->get($this->portalUrl($org))->assertOk();

        $contact->forceFill(['revoked_at' => now()])->save();

        $this->get($this->portalUrl($org))->assertRedirect($this->portalUrl($org, 'login'));
        $this->assertGuest('customer');
    }

    public function test_logout_ends_the_portal_session_only(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $contact = $this->makeContact($this->makeCustomer($org, 'Buyer Ltd'), 'buyer@example.test');
        $staff = $this->makeStaff($org, 'staff@example.test');

        $this->actingAs($staff, 'web')->actingAs($contact, 'customer');

        $this->post($this->portalUrl($org, 'logout'))->assertRedirect($this->portalUrl($org, 'login'));

        $this->assertGuest('customer');
        $this->assertAuthenticatedAs($staff, 'web');
    }
}
