<?php

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SKUControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
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

        $adminRole = Role::firstOrCreate(
            ['slug' => 'system-administrator'],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'permissions' => ['view_products', 'edit_products'],
            ]
        );

        $this->admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    public function test_admin_can_view_sku_patterns(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('sku.patterns'));

        $response->assertStatus(200);
    }

    /**
     * The date examples were hard-coded to October 2025.
     */
    public function test_pattern_examples_use_the_current_date(): void
    {
        $this->travelTo(now()->setDate(2027, 3, 9));

        $response = $this->actingAs($this->admin)->getJson(route('sku.patterns'))->assertOk();

        $examples = collect($response->json('variables'))->pluck('example', 'key');
        $this->assertSame('270309', $examples['{date}']);
        $this->assertSame('2027', $examples['{year}']);
        $this->assertSame('03', $examples['{month}']);

        $presets = collect($response->json('presets'))->pluck('example', 'pattern');
        $this->assertSame('2027-ELE-000123', $presets['{year}-{category}-{number}']);
        $this->assertSame('ELE-270309-A7K9M2', $presets['{category}-{date}-{random}']);
    }

    public function test_admin_can_generate_sku(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('sku.generate'), [
                'pattern' => 'CAT-###',
            ]);

        $response->assertStatus(200);
    }

    public function test_admin_can_check_sku_uniqueness(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('sku.check-unique'), [
                'sku' => 'UNIQUE-SKU-123',
            ]);

        $response->assertStatus(200);
    }

    public function test_guest_cannot_access_sku_routes(): void
    {
        $response = $this->get(route('sku.patterns'));

        $response->assertRedirect(route('login'));
    }
}
