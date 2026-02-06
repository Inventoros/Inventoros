<?php

namespace Tests\Unit;

use App\Models\Auth\Organization;
use App\Models\Setting;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Org',
            'email' => 'test@org.com',
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_get_throws_when_unauthenticated(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User must be authenticated to access settings');

        SettingsService::get('some.key');
    }

    public function test_get_returns_default_when_setting_not_found(): void
    {
        $this->actingAs($this->user);

        $result = SettingsService::get('nonexistent.key', 'fallback');

        $this->assertSame('fallback', $result);
    }

    public function test_get_returns_default_null_when_no_default_provided(): void
    {
        $this->actingAs($this->user);

        $result = SettingsService::get('nonexistent.key');

        $this->assertNull($result);
    }

    public function test_set_and_get_non_encrypted_value(): void
    {
        $this->actingAs($this->user);

        SettingsService::set('app.name', 'My App');
        // Clear cache so get() reads from DB through cache
        Cache::flush();

        $value = SettingsService::get('app.name');

        $this->assertSame('My App', $value);
    }

    public function test_set_overwrites_existing_value(): void
    {
        $this->actingAs($this->user);

        SettingsService::set('app.name', 'Original');
        SettingsService::set('app.name', 'Updated');
        Cache::flush();

        $value = SettingsService::get('app.name');

        $this->assertSame('Updated', $value);
    }

    public function test_set_throws_when_unauthenticated(): void
    {
        $this->expectException(RuntimeException::class);

        SettingsService::set('some.key', 'value');
    }

    public function test_set_clears_cache_for_key(): void
    {
        $this->actingAs($this->user);

        SettingsService::set('cached.key', 'value1');
        // Warm cache
        SettingsService::get('cached.key');

        SettingsService::set('cached.key', 'value2');
        Cache::flush();

        $this->assertSame('value2', SettingsService::get('cached.key'));
    }

    public function test_set_encrypted_flag_is_stored(): void
    {
        $this->actingAs($this->user);

        SettingsService::set('secret.password', 's3cr3t', true);

        $setting = Setting::where('organization_id', $this->organization->id)
            ->where('key', 'secret.password')
            ->first();

        $this->assertNotNull($setting);
        $this->assertTrue($setting->encrypted);
    }

    public function test_non_encrypted_setting_is_cached(): void
    {
        $this->actingAs($this->user);

        SettingsService::set('cached.setting', 'original');

        // Read to warm the cache
        SettingsService::get('cached.setting');

        // Direct DB update bypasses the cache
        Setting::where('organization_id', $this->organization->id)
            ->where('key', 'cached.setting')
            ->update(['value' => 'db_updated']);

        // Should still return cached value
        $value = SettingsService::get('cached.setting');
        $this->assertSame('original', $value);
    }

    public function test_settings_are_scoped_to_organization(): void
    {
        $this->actingAs($this->user);
        SettingsService::set('org.setting', 'org1value');

        $otherOrg = Organization::create(['name' => 'Other', 'email' => 'o@o.com']);
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($otherUser);
        $result = SettingsService::get('org.setting', 'default');

        $this->assertSame('default', $result);
    }

    public function test_get_email_config_returns_expected_structure(): void
    {
        $this->actingAs($this->user);

        $config = SettingsService::getEmailConfig();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('provider', $config);
        $this->assertArrayHasKey('from_address', $config);
        $this->assertArrayHasKey('from_name', $config);
        $this->assertArrayHasKey('smtp', $config);
        $this->assertArrayHasKey('mailgun', $config);
        $this->assertArrayHasKey('sendgrid', $config);
    }

    public function test_get_email_config_returns_defaults(): void
    {
        $this->actingAs($this->user);

        $config = SettingsService::getEmailConfig();

        $this->assertSame('smtp', $config['provider']);
        $this->assertSame(587, $config['smtp']['port']);
        $this->assertSame('tls', $config['smtp']['encryption']);
    }

    public function test_get_email_config_returns_stored_values(): void
    {
        $this->actingAs($this->user);

        SettingsService::set('email.provider', 'mailgun');
        SettingsService::set('email.from_address', 'noreply@example.com');
        SettingsService::set('email.from_name', 'Test App');
        Cache::flush();

        $config = SettingsService::getEmailConfig();

        $this->assertSame('mailgun', $config['provider']);
        $this->assertSame('noreply@example.com', $config['from_address']);
        $this->assertSame('Test App', $config['from_name']);
    }

    public function test_apply_email_config_sets_smtp_config(): void
    {
        $this->actingAs($this->user);

        SettingsService::set('email.provider', 'smtp');
        SettingsService::set('email.from_address', 'test@example.com');
        SettingsService::set('email.from_name', 'App');
        SettingsService::set('email.smtp.host', 'mail.example.com');
        SettingsService::set('email.smtp.port', '465');
        Cache::flush();

        SettingsService::applyEmailConfig();

        $this->assertSame('smtp', Config::get('mail.default'));
        $this->assertSame('test@example.com', Config::get('mail.from.address'));
        $this->assertSame('App', Config::get('mail.from.name'));
        $this->assertSame('mail.example.com', Config::get('mail.mailers.smtp.host'));
        $this->assertSame('465', Config::get('mail.mailers.smtp.port'));
    }

    public function test_apply_email_config_sets_mailgun_config(): void
    {
        $this->actingAs($this->user);

        SettingsService::set('email.provider', 'mailgun');
        SettingsService::set('email.mailgun.domain', 'mg.example.com');
        SettingsService::set('email.mailgun.secret', 'key-abc123');
        Cache::flush();

        SettingsService::applyEmailConfig();

        $this->assertSame('mailgun', Config::get('mail.default'));
        $this->assertSame('mg.example.com', Config::get('services.mailgun.domain'));
        $this->assertSame('key-abc123', Config::get('services.mailgun.secret'));
    }

    public function test_apply_email_config_sets_sendgrid_config(): void
    {
        $this->actingAs($this->user);

        SettingsService::set('email.provider', 'sendgrid');
        SettingsService::set('email.sendgrid.api_key', 'SG.test123');
        Cache::flush();

        SettingsService::applyEmailConfig();

        $this->assertSame('sendgrid', Config::get('mail.default'));
        $this->assertSame('SG.test123', Config::get('services.sendgrid.api_key'));
    }
}
