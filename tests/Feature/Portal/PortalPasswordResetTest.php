<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Mail\PortalPasswordResetEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalPasswordResetTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        Mail::fake();
    }

    public function test_active_contact_can_reset_their_password(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $contact = $this->makeContact($this->makeCustomer($org, 'Buyer A'), 'a@buyer.test');

        $this->get($this->portalUrl($org, 'forgot-password'))->assertOk();

        $this->post($this->portalUrl($org, 'forgot-password'), ['email' => 'a@buyer.test'])
            ->assertSessionHas('status');

        $token = null;
        Mail::assertQueued(PortalPasswordResetEmail::class, function (PortalPasswordResetEmail $mail) use (&$token, $org) {
            $token = $mail->token;

            return $mail->hasTo('a@buyer.test')
                && str_contains($mail->resetUrl, '/portal/'.$org->slug.'/reset-password/');
        });

        $this->get($this->portalUrl($org, 'reset-password/'.$token.'?email=a@buyer.test'))->assertOk();

        $this->post($this->portalUrl($org, 'reset-password'), [
            'token' => $token,
            'email' => 'a@buyer.test',
            'password' => 'brand-new-password-9',
            'password_confirmation' => 'brand-new-password-9',
        ])->assertRedirect($this->portalUrl($org, 'login'));

        $this->assertTrue(Hash::check('brand-new-password-9', $contact->fresh()->password));
    }

    public function test_a_password_reset_ends_the_contacts_other_portal_sessions(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $contact = $this->makeContact($this->makeCustomer($org, 'Buyer A'), 'a@buyer.test');

        // A session signed in before the reset (e.g. a lost laptop).
        $this->post($this->portalUrl($org, 'login'), ['email' => 'a@buyer.test', 'password' => 'secret-password']);
        $this->get($this->portalUrl($org))->assertOk();

        // The password is reset elsewhere, exactly as the reset flow does it.
        $contact->forceFill(['password' => Hash::make('brand-new-password-9'), 'remember_token' => 'rotated'])->save();
        $this->app['auth']->forgetGuards(); // a new request reads the contact afresh

        $this->get($this->portalUrl($org))->assertRedirect($this->portalUrl($org, 'login'));
        $this->assertGuest('customer');
    }

    public function test_a_password_reset_does_not_end_a_staff_session_in_the_same_browser(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $contact = $this->makeContact($this->makeCustomer($org, 'Buyer A'), 'a@buyer.test');
        $staff = $this->makeStaff($org, 'staff@acme.test');

        // A real staff sign-in, so it lives in the shared session.
        $this->post('/login', ['email' => 'staff@acme.test', 'password' => 'password']);
        $this->assertAuthenticatedAs($staff, 'web');
        $this->post($this->portalUrl($org, 'login'), ['email' => 'a@buyer.test', 'password' => 'secret-password']);
        $contact->forceFill(['password' => Hash::make('brand-new-password-9')])->save();
        $this->app['auth']->guard('customer')->setUser($contact->fresh()); // as a new request would load it

        $this->get($this->portalUrl($org))->assertRedirect($this->portalUrl($org, 'login'));
        $this->assertTrue(session()->has(auth()->guard('web')->getName()), 'The staff sign-in must survive in the session.');
    }

    public function test_unknown_revoked_or_uninvited_emails_get_the_same_answer_and_no_mail(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $customer = $this->makeCustomer($org, 'Buyer A');
        $this->makeContact($customer, 'revoked@buyer.test', attributes: ['revoked_at' => now()]);
        $this->makeContact($customer, 'pending@buyer.test', password: '');

        foreach (['nobody@buyer.test', 'revoked@buyer.test', 'pending@buyer.test'] as $email) {
            $this->post($this->portalUrl($org, 'forgot-password'), ['email' => $email])
                ->assertSessionHas('status')
                ->assertSessionHasNoErrors();
        }

        Mail::assertNothingQueued();
    }

    public function test_a_token_for_one_organization_cannot_reset_the_same_email_elsewhere(): void
    {
        $orgA = $this->makeOrganization('Org A');
        $orgB = $this->makeOrganization('Org B');
        $contactA = $this->makeContact($this->makeCustomer($orgA, 'Buyer A'), 'same@buyer.test');
        $contactB = $this->makeContact($this->makeCustomer($orgB, 'Buyer B'), 'same@buyer.test');

        $this->post($this->portalUrl($orgA, 'forgot-password'), ['email' => 'same@buyer.test']);

        $token = null;
        Mail::assertQueued(PortalPasswordResetEmail::class, function (PortalPasswordResetEmail $mail) use (&$token) {
            $token = $mail->token;

            return true;
        });

        $this->post($this->portalUrl($orgB, 'reset-password'), [
            'token' => $token,
            'email' => 'same@buyer.test',
            'password' => 'brand-new-password-9',
            'password_confirmation' => 'brand-new-password-9',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('secret-password', $contactB->fresh()->password));
        $this->assertTrue(Hash::check('secret-password', $contactA->fresh()->password));
    }

    public function test_staff_password_reset_does_not_touch_contacts(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $this->makeContact($this->makeCustomer($org, 'Buyer A'), 'a@buyer.test');

        $this->post('/forgot-password', ['email' => 'a@buyer.test']);

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }
}
