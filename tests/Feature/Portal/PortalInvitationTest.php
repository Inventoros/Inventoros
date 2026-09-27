<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\SecurityEvent;
use App\Mail\PortalInvitationEmail;
use App\Models\ActivityLog;
use App\Models\CustomerContact;
use App\Services\Portal\PortalInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalInvitationTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        Mail::fake();
    }

    public function test_staff_with_edit_customers_can_invite_a_contact(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $staff = $this->makeStaffWithPermissions($org, 'rep@example.test', ['view_customers', 'edit_customers']);
        $customer = $this->makeCustomer($org, 'Buyer Ltd');

        $this->actingAs($staff)
            ->post(route('customers.contacts.store', $customer), [
                'name' => 'Jane Buyer',
                'email' => 'Jane@Buyer.test',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $contact = CustomerContact::where('email', 'jane@buyer.test')->firstOrFail();
        $this->assertSame($org->id, $contact->organization_id);
        $this->assertSame($customer->id, $contact->customer_id);
        $this->assertNull($contact->password);
        $this->assertNotNull($contact->invited_at);
        $this->assertSame($staff->id, $contact->invited_by);

        Mail::assertQueued(PortalInvitationEmail::class, function (PortalInvitationEmail $mail) use ($contact) {
            return $mail->hasTo('jane@buyer.test')
                && str_contains($mail->acceptUrl, '/portal/'.$contact->organization->slug.'/invitation/'.$contact->id)
                && str_contains($mail->acceptUrl, 'signature=');
        });

        $log = ActivityLog::where('action', SecurityEvent::PORTAL_INVITE_SENT->value)->firstOrFail();
        $this->assertSame($staff->id, $log->user_id);
        $this->assertSame($contact->id, $log->subject_id);
    }

    public function test_staff_without_edit_customers_cannot_invite(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $viewer = $this->makeStaffWithPermissions($org, 'viewer@example.test', ['view_customers']);
        $customer = $this->makeCustomer($org, 'Buyer Ltd');

        $this->actingAs($viewer)
            ->post(route('customers.contacts.store', $customer), ['name' => 'X', 'email' => 'x@buyer.test'])
            ->assertForbidden();

        $this->assertSame(0, CustomerContact::count());
        Mail::assertNothingQueued();
    }

    public function test_staff_cannot_invite_for_another_organizations_customer(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $staffA = $this->makeStaff($orgA, 'admin@a.test');
        $customerB = $this->makeCustomer($orgB, 'Buyer B');

        $this->actingAs($staffA)
            ->post(route('customers.contacts.store', $customerB), ['name' => 'X', 'email' => 'x@buyer.test'])
            ->assertNotFound();

        $this->assertSame(0, CustomerContact::count());
    }

    public function test_invites_are_refused_while_the_portal_is_disabled(): void
    {
        $org = $this->makeOrganization('Acme Wholesale', portalEnabled: false);
        $staff = $this->makeStaff($org, 'admin@example.test');
        $customer = $this->makeCustomer($org, 'Buyer Ltd');

        $this->actingAs($staff)
            ->post(route('customers.contacts.store', $customer), ['name' => 'X', 'email' => 'x@buyer.test'])
            ->assertSessionHas('error');

        $this->assertSame(0, CustomerContact::count());
        Mail::assertNothingQueued();
    }

    public function test_an_email_can_only_be_one_contact_per_organization(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $staff = $this->makeStaff($org, 'admin@example.test');
        $customerA = $this->makeCustomer($org, 'Buyer A');
        $customerB = $this->makeCustomer($org, 'Buyer B');
        $this->makeContact($customerA, 'shared@buyer.test');

        $this->actingAs($staff)
            ->post(route('customers.contacts.store', $customerB), ['name' => 'X', 'email' => 'shared@buyer.test'])
            ->assertSessionHasErrors('email');

        // The same email is fine in a different organization.
        $otherOrg = $this->makeOrganization('Other Org');
        $otherStaff = $this->makeStaff($otherOrg, 'admin@other.test');
        $this->actingAs($otherStaff)
            ->post(route('customers.contacts.store', $this->makeCustomer($otherOrg, 'Buyer C')), ['name' => 'X', 'email' => 'shared@buyer.test'])
            ->assertSessionHasNoErrors();
    }

    public function test_contact_accepts_the_invitation_and_is_signed_in(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $staff = $this->makeStaff($org, 'admin@example.test');
        $contact = $this->invite($staff, $this->makeCustomer($org, 'Buyer Ltd'));
        $url = app(PortalInvitationService::class)->acceptUrl($contact);

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/Auth/AcceptInvitation')
                ->where('email', 'jane@buyer.test'));

        $this->post($url, [
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
        ])->assertRedirect($this->portalUrl($org));

        $this->assertAuthenticatedAs($contact->fresh(), 'customer');
        $this->assertNotNull($contact->fresh()->password);
        $this->assertNotNull($contact->fresh()->activated_at);

        // The link is single use.
        $this->post('/portal/'.$org->slug.'/logout');
        $this->get($url)->assertForbidden();
    }

    public function test_expired_invitation_is_rejected(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $contact = $this->invite($this->makeStaff($org, 'admin@example.test'), $this->makeCustomer($org, 'Buyer Ltd'));
        $url = app(PortalInvitationService::class)->acceptUrl($contact);

        $this->travel(PortalInvitationService::EXPIRES_IN_DAYS + 1)->days();

        $this->get($url)->assertForbidden();
        $this->post($url, [
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
        ])->assertForbidden();
        $this->assertNull($contact->fresh()->password);
    }

    public function test_tampered_invitation_is_rejected(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $staff = $this->makeStaff($org, 'admin@example.test');
        $customer = $this->makeCustomer($org, 'Buyer Ltd');
        $victim = $this->invite($staff, $customer, 'victim@buyer.test');
        $attacker = $this->invite($staff, $customer, 'attacker@buyer.test');

        $url = app(PortalInvitationService::class)->acceptUrl($attacker);
        $tampered = str_replace('/invitation/'.$attacker->id, '/invitation/'.$victim->id, $url);

        $this->get($tampered)->assertForbidden();
        $this->post($tampered, [
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
        ])->assertForbidden();
        $this->assertNull($victim->fresh()->password);

        // A link without its signature is rejected too.
        $this->get(strtok($url, '?'))->assertForbidden();
    }

    public function test_resending_invalidates_the_previous_link(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $staff = $this->makeStaff($org, 'admin@example.test');
        $customer = $this->makeCustomer($org, 'Buyer Ltd');
        $contact = $this->invite($staff, $customer);
        $oldUrl = app(PortalInvitationService::class)->acceptUrl($contact);

        $this->travel(5)->minutes();

        $this->actingAs($staff)
            ->post(route('customers.contacts.resend', [$customer, $contact]))
            ->assertSessionHas('success');

        Mail::assertQueued(PortalInvitationEmail::class, 2);

        $this->get($oldUrl)->assertForbidden();
        $this->get(app(PortalInvitationService::class)->acceptUrl($contact->fresh()))->assertOk();
    }

    public function test_revoking_blocks_the_invitation_and_sign_in(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $staff = $this->makeStaff($org, 'admin@example.test');
        $customer = $this->makeCustomer($org, 'Buyer Ltd');
        $invited = $this->invite($staff, $customer);
        $url = app(PortalInvitationService::class)->acceptUrl($invited);
        $active = $this->makeContact($customer, 'active@buyer.test');

        $this->actingAs($staff)
            ->delete(route('customers.contacts.destroy', [$customer, $invited]))
            ->assertSessionHas('success');
        $this->actingAs($staff)
            ->delete(route('customers.contacts.destroy', [$customer, $active]))
            ->assertSessionHas('success');

        $this->assertNotNull($invited->fresh()->revoked_at);
        $this->assertNull($active->fresh()->password);
        $this->assertSame(2, ActivityLog::where('action', SecurityEvent::PORTAL_ACCESS_REVOKED->value)->count());

        $this->get($url)->assertForbidden();
        $this->post($this->portalUrl($org, 'login'), [
            'email' => 'active@buyer.test',
            'password' => 'secret-password',
        ])->assertSessionHasErrors('email');
    }

    public function test_contacts_of_another_customer_cannot_be_managed_through_this_customer(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $staff = $this->makeStaff($org, 'admin@example.test');
        $customerA = $this->makeCustomer($org, 'Buyer A');
        $customerB = $this->makeCustomer($org, 'Buyer B');
        $contactB = $this->makeContact($customerB, 'b@buyer.test');

        $this->actingAs($staff)
            ->delete(route('customers.contacts.destroy', [$customerA, $contactB]))
            ->assertNotFound();

        $this->assertNull($contactB->fresh()->revoked_at);
    }

    public function test_customer_show_lists_contacts_and_portal_state(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $staff = $this->makeStaff($org, 'admin@example.test');
        $customer = $this->makeCustomer($org, 'Buyer Ltd');
        $this->makeContact($customer, 'active@buyer.test');
        $this->makeContact($customer, 'pending@buyer.test', password: '');
        $this->makeContact($this->makeCustomer($org, 'Someone Else'), 'else@buyer.test');

        $this->actingAs($staff)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('contacts', 2)
                ->where('contacts.0.email', 'active@buyer.test')
                ->where('contacts.0.status', 'active')
                ->where('contacts.1.status', 'invited')
                ->missing('contacts.0.password')
                ->where('portal.enabled', true)
                ->where('portal.canManageContacts', true));
    }

    private function invite($staff, $customer, string $email = 'jane@buyer.test'): CustomerContact
    {
        $this->actingAs($staff)
            ->post(route('customers.contacts.store', $customer), ['name' => 'Jane Buyer', 'email' => $email])
            ->assertSessionHasNoErrors();

        return CustomerContact::where('email', $email)->where('organization_id', $customer->organization_id)->firstOrFail();
    }
}
