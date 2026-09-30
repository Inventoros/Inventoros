<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Portal\BuildsPortalFixtures;
use Tests\TestCase;

/**
 * The organization's regional settings (currency, date format, time format)
 * were saved but never reached the browser, so every page formatted money and
 * dates its own way. They are shared with every page, staff and portal, so
 * the frontend formatters can honour them.
 */
class RegionalFormatSharingTest extends TestCase
{
    use BuildsPortalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
    }

    private function staffIn(Organization $organization): User
    {
        $user = User::create([
            'name' => 'Regional User',
            'email' => 'regional@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $organization->id,
            'role' => 'admin',
        ]);

        $role = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            ['name' => 'Administrator', 'is_system' => true, 'permissions' => []],
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    public function test_staff_pages_receive_the_organization_regional_settings(): void
    {
        $organization = Organization::create([
            'name' => 'Regional Org',
            'email' => 'regional@organization.com',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
        ]);

        $this->actingAs($this->staffIn($organization))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('regional.currency', 'EUR')
                ->where('regional.date_format', 'd.m.Y')
                ->where('regional.time_format', 'H:i')
            );
    }

    public function test_unset_formats_are_shared_as_null(): void
    {
        $organization = Organization::create([
            'name' => 'Plain Org',
            'email' => 'plain@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->actingAs($this->staffIn($organization))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('regional.currency', 'USD')
                ->where('regional.date_format', null)
                ->where('regional.time_format', null)
            );
    }

    public function test_portal_pages_receive_the_portal_organization_regional_settings(): void
    {
        $organization = $this->makeOrganization('Portal Regional');
        $organization->forceFill(['currency' => 'CAD', 'date_format' => 'Y/m/d'])->save();
        $contact = $this->makeContact($this->makeCustomer($organization, 'Buyer'), 'buyer@regional.test');

        $this->actingAs($contact, 'customer')
            ->get($this->portalUrl($organization))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('regional.currency', 'CAD')
                ->where('regional.date_format', 'Y/m/d')
            );
    }
}
