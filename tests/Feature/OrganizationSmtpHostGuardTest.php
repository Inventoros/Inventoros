<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TestEmail;
use App\Models\Auth\Organization;
use App\Models\Setting;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\OrganizationMailer;
use App\Support\PublicHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Organization SMTP settings make the app server open a connection to the
 * configured host. Tenants other than the administrator organization must
 * not be able to aim it at loopback, private or metadata addresses, and the
 * test-email endpoint must not report what the server found there.
 */
class OrganizationSmtpHostGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $adminOrg;

    private Organization $tenantOrg;

    private User $adminOrgAdmin;

    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        // The first organization is the administrator organization.
        $this->adminOrg = Organization::create(['name' => 'Owner', 'email' => 'owner@org.test']);
        $this->tenantOrg = Organization::create(['name' => 'Tenant', 'email' => 'tenant@org.test']);

        $this->adminOrgAdmin = $this->admin($this->adminOrg, 'owner-admin@test.com');
        $this->tenantAdmin = $this->admin($this->tenantOrg, 'tenant-admin@test.com');
    }

    private function admin(Organization $org, string $email): User
    {
        $user = User::create([
            'name' => 'Admin', 'email' => $email, 'password' => bcrypt('password'),
            'organization_id' => $org->id,
        ]);
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    /** @param array<int, string> $ips */
    private function dns(array $ips): void
    {
        $this->mock(PublicHostResolver::class)->shouldReceive('resolve')->andReturn($ips);
    }

    private function noDns(): void
    {
        $this->mock(PublicHostResolver::class)->shouldNotReceive('resolve');
    }

    /** @return array<string, mixed> */
    private function smtpForm(string $host): array
    {
        return [
            'provider' => 'smtp',
            'from_address' => 'ops@example.com',
            'from_name' => 'Ops',
            'smtp' => ['host' => $host, 'port' => 25, 'encryption' => 'none'],
        ];
    }

    private function storedHost(Organization $org): ?string
    {
        return Setting::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('key', 'email.smtp.host')
            ->value('value');
    }

    private function storeSmtpDirectly(Organization $org, string $host): void
    {
        foreach ([
            'email.provider' => 'smtp',
            'email.from_address' => 'ops@example.com',
            'email.from_name' => 'Ops',
            'email.smtp.host' => $host,
            'email.smtp.port' => '25',
        ] as $key => $value) {
            Setting::create(['organization_id' => $org->id, 'key' => $key, 'value' => $value, 'encrypted' => false]);
        }
    }

    public function test_a_tenant_cannot_save_a_loopback_or_private_smtp_ip(): void
    {
        $this->noDns();

        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '[::1]', 'localhost'] as $host) {
            $this->actingAs($this->tenantAdmin)
                ->post(route('settings.email.update'), $this->smtpForm($host))
                ->assertSessionHasErrors('smtp.host');
        }

        $this->assertNull($this->storedHost($this->tenantOrg));
    }

    public function test_a_tenant_cannot_save_a_hostname_that_resolves_to_a_private_address(): void
    {
        $this->dns(['192.168.1.20']);

        $this->actingAs($this->tenantAdmin)
            ->post(route('settings.email.update'), $this->smtpForm('relay.internal.example'))
            ->assertSessionHasErrors('smtp.host');

        $this->assertNull($this->storedHost($this->tenantOrg));
    }

    public function test_a_tenant_can_save_a_public_smtp_host(): void
    {
        $this->dns(['8.8.8.8']);

        $this->actingAs($this->tenantAdmin)
            ->post(route('settings.email.update'), $this->smtpForm('smtp.example.com'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('smtp.example.com', $this->storedHost($this->tenantOrg));
    }

    public function test_the_administrator_organization_keeps_local_relays(): void
    {
        $this->noDns();

        $this->actingAs($this->adminOrgAdmin)
            ->post(route('settings.email.update'), $this->smtpForm('127.0.0.1'))
            ->assertSessionHasNoErrors();

        $this->assertSame('127.0.0.1', $this->storedHost($this->adminOrg));
        // The exemption belongs to the administrator organization only.
        $this->assertNull($this->storedHost($this->tenantOrg));
    }

    public function test_operator_allowlisted_relays_are_accepted_for_tenants(): void
    {
        config(['mail.organization_smtp_allowed_hosts' => ['mailpit']]);
        $this->noDns();

        $this->actingAs($this->tenantAdmin)
            ->post(route('settings.email.update'), $this->smtpForm('Mailpit'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Mailpit', $this->storedHost($this->tenantOrg));
        $this->assertSame('organization_'.$this->tenantOrg->id, OrganizationMailer::for($this->tenantOrg->id));
    }

    public function test_a_stored_private_host_is_refused_before_connecting(): void
    {
        $this->storeSmtpDirectly($this->tenantOrg, 'relay.internal.example');
        $this->dns(['10.1.2.3']);

        $this->expectException(RuntimeException::class);
        OrganizationMailer::for($this->tenantOrg->id);
    }

    public function test_a_public_stored_host_still_builds_the_tenant_mailer(): void
    {
        $this->storeSmtpDirectly($this->tenantOrg, 'smtp.example.com');
        $this->dns(['8.8.8.8']);

        $this->assertSame('organization_'.$this->tenantOrg->id, OrganizationMailer::for($this->tenantOrg->id));
    }

    public function test_the_test_email_endpoint_refuses_a_private_host_with_a_generic_error(): void
    {
        Mail::fake();
        $this->storeSmtpDirectly($this->tenantOrg, 'relay.internal.example');
        $this->dns(['10.1.2.3']);

        $response = $this->actingAs($this->tenantAdmin)
            ->postJson(route('settings.email.test'), ['test_email' => 'me@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Failed to send test email. Check the email settings and try again.');

        $this->assertStringNotContainsString('10.1.2.3', $response->getContent());
        $this->assertStringNotContainsString('relay.internal', $response->getContent());
        Mail::assertNothingSent();
    }

    public function test_transport_errors_are_not_returned_to_a_tenant(): void
    {
        Mail::shouldReceive('mailer')->andThrow(new RuntimeException('Connection could not be established with host "10.0.0.9:6379"'));

        $response = $this->actingAs($this->tenantAdmin)
            ->postJson(route('settings.email.test'), ['test_email' => 'me@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Failed to send test email. Check the email settings and try again.');

        $this->assertStringNotContainsString('10.0.0.9', $response->getContent());
    }

    /**
     * The administrator organization's admins are trusted with the server;
     * hosting control panel users rely on the transport error to fix SMTP.
     */
    public function test_the_administrator_organization_still_sees_the_transport_error(): void
    {
        Mail::shouldReceive('mailer')->andThrow(new RuntimeException('Connection refused by mail.local'));

        $this->actingAs($this->adminOrgAdmin)
            ->postJson(route('settings.email.test'), ['test_email' => 'me@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Failed to send test email: Connection refused by mail.local');
    }

    public function test_an_unresolvable_host_gets_its_own_message(): void
    {
        $this->dns([]);

        $this->actingAs($this->tenantAdmin)
            ->post(route('settings.email.update'), $this->smtpForm('no-such-host.example.com'))
            ->assertSessionHasErrors(['smtp.host' => 'The SMTP host could not be found. Check the host name.']);

        $this->assertNull($this->storedHost($this->tenantOrg));
    }

    public function test_a_private_host_keeps_the_public_server_message(): void
    {
        $this->dns(['10.1.2.3']);

        $this->actingAs($this->tenantAdmin)
            ->post(route('settings.email.update'), $this->smtpForm('relay.internal.example'))
            ->assertSessionHasErrors(['smtp.host' => 'The SMTP host must be a public mail server.']);
    }

    public function test_a_refused_host_is_logged_as_an_error_before_sending(): void
    {
        $this->storeSmtpDirectly($this->tenantOrg, 'relay.internal.example');
        $this->dns(['10.1.2.3']);
        Log::spy();

        try {
            OrganizationMailer::for($this->tenantOrg->id);
            $this->fail('A refused host must not build a mailer.');
        } catch (RuntimeException) {
            // expected
        }

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => str_contains($message, 'SMTP host')
            && $context['organization_id'] === $this->tenantOrg->id
            && $context['host'] === 'relay.internal.example')->once();
    }

    public function test_the_check_command_lists_organizations_whose_host_is_refused(): void
    {
        $this->storeSmtpDirectly($this->tenantOrg, 'relay.internal.example');
        $this->storeSmtpDirectly($this->adminOrg, '127.0.0.1');
        $this->dns(['10.1.2.3']);

        $this->artisan('inventoros:check-smtp-hosts')
            ->expectsOutputToContain('relay.internal.example')
            ->doesntExpectOutputToContain('127.0.0.1')
            ->assertExitCode(1);
    }

    public function test_the_check_command_passes_when_every_host_is_allowed(): void
    {
        $this->storeSmtpDirectly($this->tenantOrg, 'smtp.example.com');
        $this->dns(['8.8.8.8']);

        $this->artisan('inventoros:check-smtp-hosts')
            ->expectsOutputToContain('No organization')
            ->assertExitCode(0);
    }

    public function test_the_test_email_endpoint_is_throttled(): void
    {
        // No SMTP host stored: the system mailer is used.
        Mail::fake();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->tenantAdmin)
                ->postJson(route('settings.email.test'), ['test_email' => 'me@example.com'])
                ->assertOk();
        }

        $this->actingAs($this->tenantAdmin)
            ->postJson(route('settings.email.test'), ['test_email' => 'me@example.com'])
            ->assertStatus(429);
    }
}
