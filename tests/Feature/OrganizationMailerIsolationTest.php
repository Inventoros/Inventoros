<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LowStockEmail;
use App\Mail\PortalInvitationEmail;
use App\Mail\PortalPasswordResetEmail;
use App\Mail\TestEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use App\Models\Auth\Organization;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Setting;
use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * A long-lived queue worker delivers mail for every organization. Each org's
 * mail must go through that org's own transport, and applying one org's
 * settings must never leak into the next job: the old code mutated the global
 * mail config and MailManager cached the first SMTP mailer it built, so the
 * first org to send fixed the transport (and From address) for everyone,
 * including other orgs' password-reset and invitation mail.
 */
class OrganizationMailerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        // The instance's own (system) mailer, as configured in .env.
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'system-smtp.test');
        Config::set('mail.mailers.smtp.port', 2525);
        Config::set('mail.from.address', 'noreply@system.test');
        Config::set('mail.from.name', 'System');
        app('mail.manager')->forgetMailers();

        $this->orgA = $this->orgWithSmtp('Acme', 'smtp.acme.test', 'store@acme.test');
        $this->orgB = $this->orgWithSmtp('Globex', 'smtp.globex.test', 'shop@globex.test');
    }

    private function orgWithSmtp(string $name, string $host, string $from): Organization
    {
        $org = Organization::create([
            'name' => $name, 'email' => strtolower($name).'@org.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        foreach ([
            'email.provider' => 'smtp',
            'email.from_address' => $from,
            'email.from_name' => $name,
            'email.smtp.host' => $host,
            'email.smtp.port' => '587',
            'email.smtp.username' => strtolower($name),
        ] as $key => $value) {
            Setting::create(['organization_id' => $org->id, 'key' => $key, 'value' => $value, 'encrypted' => false]);
        }

        return $org;
    }

    /**
     * Build the mailable the way the worker does and return the SMTP host
     * of the mailer that would deliver it.
     */
    private function deliveringHost(Mailable $mailable): string
    {
        $mailable->build();

        $transport = app('mail.manager')->mailer($mailable->mailer)->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);

        return $transport->getStream()->getHost();
    }

    private function lowStock(Organization $org): LowStockEmail
    {
        return new LowStockEmail(['organization_id' => $org->id]);
    }

    private function contact(Organization $org): CustomerContact
    {
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Cust '.$org->name]);

        return CustomerContact::create([
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
            'name' => 'Pat',
            'email' => 'pat'.$org->id.'@customer.test',
        ]);
    }

    public function test_two_orgs_in_one_worker_each_use_their_own_transport(): void
    {
        $this->assertSame('smtp.acme.test', $this->deliveringHost($this->lowStock($this->orgA)));
        $this->assertSame('smtp.globex.test', $this->deliveringHost($this->lowStock($this->orgB)));
        // And back again: nothing cached from the previous job.
        $this->assertSame('smtp.acme.test', $this->deliveringHost($this->lowStock($this->orgA)));
    }

    public function test_each_orgs_from_address_is_scoped_to_its_own_mailer(): void
    {
        $a = $this->lowStock($this->orgA);
        $a->build();
        $b = $this->lowStock($this->orgB);
        $b->build();

        $this->assertSame('store@acme.test', Config::get("mail.mailers.{$a->mailer}.from.address"));
        $this->assertSame('shop@globex.test', Config::get("mail.mailers.{$b->mailer}.from.address"));
    }

    public function test_applying_an_orgs_settings_never_changes_the_global_mailer(): void
    {
        $this->lowStock($this->orgA)->build();

        $this->assertSame('smtp', Config::get('mail.default'));
        $this->assertSame('system-smtp.test', Config::get('mail.mailers.smtp.host'));
        $this->assertSame('noreply@system.test', Config::get('mail.from.address'));
        $this->assertSame('system-smtp.test', app('mail.manager')->mailer()->getSymfonyTransport()->getStream()->getHost());
    }

    public function test_changed_settings_take_effect_for_the_next_job(): void
    {
        $this->assertSame('smtp.acme.test', $this->deliveringHost($this->lowStock($this->orgA)));

        Setting::where('organization_id', $this->orgA->id)->where('key', 'email.smtp.host')->update(['value' => 'smtp2.acme.test']);
        cache()->flush();

        $this->assertSame('smtp2.acme.test', $this->deliveringHost($this->lowStock($this->orgA)));
    }

    public function test_an_org_without_a_configured_transport_uses_the_system_mailer(): void
    {
        $bare = Organization::create(['name' => 'Bare', 'email' => 'bare@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);

        $this->assertSame('system-smtp.test', $this->deliveringHost($this->lowStock($bare)));
    }

    public function test_portal_password_reset_mail_uses_the_system_mailer(): void
    {
        // Another org's mail went out first in this worker.
        $this->deliveringHost($this->lowStock($this->orgA));

        $reset = new PortalPasswordResetEmail($this->contact($this->orgB), 'secret-token');

        $this->assertSame('system-smtp.test', $this->deliveringHost($reset));
    }

    public function test_the_settings_test_email_uses_the_orgs_mailer_without_touching_global_config(): void
    {
        Mail::fake();
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => bcrypt('x'),
            'organization_id' => $this->orgA->id, 'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->postJson(route('settings.email.test'), ['test_email' => 'me@acme.test'])
            ->assertOk();

        Mail::assertSent(TestEmail::class, fn (TestEmail $m) => $m->mailer === 'organization_'.$this->orgA->id);
        $this->assertSame('smtp', Config::get('mail.default'));
        $this->assertSame('system-smtp.test', Config::get('mail.mailers.smtp.host'));
        $this->assertSame('noreply@system.test', Config::get('mail.from.address'));
    }

    public function test_portal_invitation_mail_uses_the_system_mailer(): void
    {
        $this->deliveringHost($this->lowStock($this->orgA));

        $invite = new PortalInvitationEmail($this->contact($this->orgB), 'https://example.test/accept/xyz', 7);

        $this->assertSame('system-smtp.test', $this->deliveringHost($invite));
    }
}
