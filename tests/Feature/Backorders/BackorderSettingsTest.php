<?php

declare(strict_types=1);

namespace Tests\Feature\Backorders;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Support\BackorderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackorderSettingsTest extends TestCase
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
            'name' => 'BO Settings Org',
            'email' => 'bo-settings@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@bo.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->admin->forceFill(['role' => 'admin'])->save();

        $this->member = User::create([
            'name' => 'Member',
            'email' => 'member@bo.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);
        $this->member->forceFill(['role' => 'member'])->save();

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => ['view_settings', 'manage_organization', 'view_orders', 'view_products'],
            ]
        );
        $memberRole = Role::firstOrCreate(
            ['slug' => 'system-member'],
            [
                'name' => 'Member',
                'description' => 'Basic member access',
                'is_system' => true,
                'permissions' => ['view_products', 'view_orders'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->member->roles()->syncWithoutDetaching([$memberRole->id]);
    }

    public function test_backorder_settings_default_to_off(): void
    {
        $settings = BackorderSettings::forOrganization($this->organization);

        $this->assertFalse($settings->enabled);
        $this->assertFalse($settings->defaultAllow);
    }

    public function test_admin_can_enable_backorders(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('settings.organization.update.backorders'), [
                'enabled' => true,
                'default_allow' => true,
            ])
            ->assertRedirect();

        $settings = BackorderSettings::forOrganization($this->organization->fresh());
        $this->assertTrue($settings->enabled);
        $this->assertTrue($settings->defaultAllow);

        $stored = ($this->organization->fresh()->settings ?? [])[BackorderSettings::KEY] ?? [];
        $this->assertSame(true, $stored['enabled']);
    }

    public function test_member_cannot_update_backorder_settings(): void
    {
        $this->actingAs($this->member)
            ->patch(route('settings.organization.update.backorders'), [
                'enabled' => true,
                'default_allow' => true,
            ])
            ->assertStatus(403);

        $this->assertFalse(BackorderSettings::forOrganization($this->organization->fresh())->enabled);
    }
}
