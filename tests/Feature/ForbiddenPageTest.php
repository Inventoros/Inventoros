<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ForbiddenPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');
        $this->actingAs(User::factory()->create(['organization_id' => Organization::factory()->create()->id, 'role' => 'member']));
    }

    public function test_direct_browser_permission_denial_has_the_application_recovery_page(): void
    {
        $this->get('/users')->assertForbidden()->assertInertia(fn (Assert $page) => $page
            ->component('Errors/403')->where('previousUrl', route('dashboard')));
    }

    public function test_an_admin_guard_uses_the_same_recovery_page(): void
    {
        $this->get('/settings/organization')->assertForbidden()->assertInertia(fn (Assert $page) => $page->component('Errors/403'));
    }

    public function test_json_permission_denial_keeps_its_json_contract(): void
    {
        $this->getJson('/users')->assertForbidden()->assertJson(['message' => 'You do not have the required permissions to perform this action.']);
    }

    public function test_back_navigation_cannot_loop_or_leave_the_application(): void
    {
        foreach ([url('/users'), 'https://external.example.test/page'] as $previous) {
            $this->from($previous)->get('/users')->assertForbidden()->assertInertia(fn (Assert $page) => $page
                ->component('Errors/403')->where('previousUrl', route('dashboard')));
        }
    }
}
