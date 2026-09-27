<?php

namespace Tests\Feature;

use App\Mail\TestEmail;
use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The settings area: the hub, the email/SMTP page and the dead
 * organization-users routes.
 */
class SettingsWiringTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $admin;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->admin->forceFill(['role' => 'admin'])->save();

        $this->member = User::create([
            'name' => 'Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->member->forceFill(['role' => 'member'])->save();
    }

    public function test_settings_hub_renders_for_admins(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Index'));
    }

    /**
     * Account settings and two-factor are for everyone, so the hub that links
     * to them cannot be gated on an admin permission.
     */
    public function test_settings_hub_renders_for_members(): void
    {
        $this->actingAs($this->member)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Index'));
    }

    public function test_email_settings_route_renders_the_email_page_with_its_config(): void
    {
        $this->actingAs($this->admin);
        SettingsService::set('email.provider', 'smtp');
        SettingsService::set('email.from_address', 'ops@example.com');
        SettingsService::set('email.from_name', 'Ops');
        SettingsService::set('email.smtp.host', 'smtp.example.com');
        SettingsService::set('email.smtp.port', 2525);

        $this->get(route('settings.email.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Email')
                ->where('emailConfig.provider', 'smtp')
                ->where('emailConfig.from_address', 'ops@example.com')
                ->where('emailConfig.smtp.host', 'smtp.example.com')
                ->where('emailConfig.smtp.port', '2525')
                ->has('userPreferences')
            );
    }

    /**
     * Wiring the page up must not hand stored provider credentials to the
     * browser: the form shows that a secret is set, never the secret itself.
     */
    public function test_email_settings_page_does_not_expose_stored_secrets(): void
    {
        $this->actingAs($this->admin);
        SettingsService::set('email.smtp.password', 'smtp-hunter2', true);
        SettingsService::set('email.mailgun.secret', 'mg-hunter2', true);
        SettingsService::set('email.sendgrid.api_key', 'sg-hunter2', true);

        $response = $this->get(route('settings.email.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Email')
                ->where('emailConfig.smtp.password', '')
                ->where('emailConfig.smtp.password_set', true)
                ->where('emailConfig.mailgun.secret', '')
                ->where('emailConfig.mailgun.secret_set', true)
                ->where('emailConfig.sendgrid.api_key', '')
                ->where('emailConfig.sendgrid.api_key_set', true)
            );

        $this->assertStringNotContainsString('hunter2', $response->getContent());
    }

    public function test_saving_email_settings_with_a_blank_secret_keeps_the_stored_one(): void
    {
        $this->actingAs($this->admin);
        SettingsService::set('email.smtp.password', 'keep-me', true);

        $this->post(route('settings.email.update'), [
            'provider' => 'smtp',
            'from_address' => 'ops@example.com',
            'from_name' => 'Ops',
            'smtp' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'username' => 'ops',
                'password' => '',
                'encryption' => 'tls',
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('keep-me', SettingsService::get('email.smtp.password'));
        $this->assertSame('smtp.example.com', SettingsService::get('email.smtp.host'));
    }

    public function test_saving_email_settings_with_a_new_secret_replaces_it(): void
    {
        $this->actingAs($this->admin);
        SettingsService::set('email.smtp.password', 'old', true);

        $this->post(route('settings.email.update'), [
            'provider' => 'smtp',
            'from_address' => 'ops@example.com',
            'from_name' => 'Ops',
            'smtp' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'password' => 'new-secret',
                'encryption' => 'tls',
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('new-secret', SettingsService::get('email.smtp.password'));
    }

    public function test_members_cannot_open_email_settings(): void
    {
        $this->actingAs($this->member)
            ->get(route('settings.email.index'))
            ->assertForbidden();
    }

    /**
     * settings.organization.users.* duplicated /users and rendered a page
     * component that does not exist.
     */
    public function test_duplicate_organization_user_routes_are_gone(): void
    {
        foreach (['index', 'store', 'update', 'destroy'] as $action) {
            $this->assertFalse(Route::has("settings.organization.users.{$action}"), "settings.organization.users.{$action} should not be registered.");
        }
    }

    public function test_old_organization_users_url_redirects_to_user_management(): void
    {
        $this->actingAs($this->admin)
            ->get('/settings/organization/users')
            ->assertRedirect(route('users.index'));
    }

    /**
     * A non-admin who has been granted manage_organization through a role.
     */
    private function orgManager(): User
    {
        $role = Role::create([
            'name' => 'Org Manager',
            'slug' => 'org-manager',
            'organization_id' => $this->organization->id,
            'permissions' => ['manage_organization'],
        ]);

        $user = User::create([
            'name' => 'Org Manager',
            'email' => 'orgmanager@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $user->forceFill(['role' => 'member'])->save();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * The route and the settings hub gate email settings on
     * manage_organization, so the controller must not additionally demand
     * the admin role: that would put a link on the hub that opens a 403.
     */
    public function test_a_user_with_manage_organization_can_open_email_settings(): void
    {
        $this->actingAs($this->orgManager())
            ->get(route('settings.email.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Email'));
    }

    public function test_a_user_with_manage_organization_can_save_email_settings(): void
    {
        $this->actingAs($this->orgManager())
            ->post(route('settings.email.update'), [
                'provider' => 'smtp',
                'from_address' => 'ops@example.com',
                'from_name' => 'Ops',
                'smtp' => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('smtp.example.com', SettingsService::get('email.smtp.host'));
    }

    public function test_the_hub_does_not_limit_email_settings_to_the_admin_role(): void
    {
        $hub = (string) file_get_contents(resource_path('js/Pages/Settings/Index.vue'));

        $this->assertMatchesRegularExpression(
            "/route\('settings\.email\.index'\),[^}]*perm: 'manage_organization',\s*\}/",
            $hub,
            'The email settings card should be gated on manage_organization only.',
        );
    }

    public function test_members_cannot_send_a_test_email(): void
    {
        Mail::fake();

        $this->actingAs($this->member)
            ->postJson(route('settings.email.test'), ['test_email' => 'to@example.com'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    /**
     * The page sends the test with axios. A redirect is followed and lands
     * on a 200, which the page showed as success even when sending failed.
     */
    public function test_a_successful_test_email_returns_json_success(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)
            ->postJson(route('settings.email.test'), ['test_email' => 'to@example.com'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        Mail::assertSent(TestEmail::class, fn ($mail) => $mail->hasTo('to@example.com'));
    }

    public function test_a_failed_test_email_returns_an_error_the_page_can_show(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused by smtp.example.com'));

        $this->actingAs($this->admin)
            ->postJson(route('settings.email.test'), ['test_email' => 'to@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Failed to send test email: Connection refused by smtp.example.com');
    }

    public function test_an_invalid_test_email_address_is_a_validation_error(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('settings.email.test'), ['test_email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('test_email');
    }
}
