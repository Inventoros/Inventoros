<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalSettingsTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
    }

    public function test_the_portal_is_off_by_default(): void
    {
        $org = \App\Models\Auth\Organization::create(['name' => 'Fresh Org', 'currency' => 'USD', 'timezone' => 'UTC']);

        $this->assertFalse($org->fresh()->portal_enabled);
        $this->get('/portal/'.$org->slug.'/login')->assertNotFound();
    }

    public function test_admin_can_switch_the_portal_on_and_off(): void
    {
        $org = $this->makeOrganization('Acme Wholesale', portalEnabled: false);
        $admin = $this->makeStaff($org, 'admin@example.test');

        $this->actingAs($admin)
            ->patch(route('settings.organization.update.portal'), ['portal_enabled' => true])
            ->assertSessionHas('success');
        $this->assertTrue($org->fresh()->portal_enabled);
        $this->get('/portal/'.$org->slug.'/login')->assertOk();

        $this->actingAs($admin)
            ->patch(route('settings.organization.update.portal'), ['portal_enabled' => false]);
        $this->assertFalse($org->fresh()->portal_enabled);
    }

    public function test_non_admin_cannot_switch_the_portal(): void
    {
        $org = $this->makeOrganization('Acme Wholesale', portalEnabled: false);
        $member = $this->makeStaffWithPermissions($org, 'rep@example.test', ['view_settings', 'edit_customers']);

        $this->actingAs($member)
            ->patch(route('settings.organization.update.portal'), ['portal_enabled' => true])
            ->assertForbidden();

        $this->assertFalse($org->fresh()->portal_enabled);
    }

    public function test_settings_page_exposes_the_portal_url(): void
    {
        $org = $this->makeOrganization('Acme Wholesale');
        $admin = $this->makeStaff($org, 'admin@example.test');

        $this->actingAs($admin)
            ->get(route('settings.organization.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('portal.enabled', true)
                ->where('portal.login_url', url('/portal/'.$org->slug.'/login')));
    }
}
