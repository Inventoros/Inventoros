<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $viewOnlyUser;
    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'email' => 'test@organization.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $this->viewOnlyUser = User::create([
            'name' => 'View Only User',
            'email' => 'viewer@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'member',
        ]);

        $this->createSystemRoles();
    }

    protected function createSystemRoles(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['view_reports'],
            ]
        );

        $viewerRole = Role::firstOrCreate(
            ['slug' => 'system-viewer'],
            [
                'name' => 'Viewer',
                'is_system' => true,
                'permissions' => [],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->viewOnlyUser->roles()->syncWithoutDetaching([$viewerRole->id]);
    }

    public function test_admin_can_view_reports_index(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.index'));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_inventory_valuation_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.inventory-valuation'));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_stock_movement_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.stock-movement'));

        $response->assertStatus(200);
    }

    public function test_admin_can_view_low_stock_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.low-stock'));

        $response->assertStatus(200);
    }

    public function test_user_without_permission_cannot_view_reports(): void
    {
        $response = $this->actingAs($this->viewOnlyUser)
            ->get(route('reports.index'));

        $response->assertStatus(403);
    }

    public function test_guest_cannot_view_reports(): void
    {
        $response = $this->get(route('reports.index'));

        $response->assertRedirect(route('login'));
    }
}
