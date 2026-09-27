<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Services\WarehouseAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WarehouseAccessSettingTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();
    }

    public function test_setting_is_off_by_default_and_shown_on_the_warehouse_list(): void
    {
        $this->actingAs($this->admin)
            ->get(route('warehouses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('restrictToAssigned', false));
    }

    public function test_admin_can_turn_on_restriction_for_unassigned_users(): void
    {
        $this->actingAs($this->admin)
            ->post(route('warehouses.access-policy'), ['restrict_to_assigned' => true])
            ->assertRedirect();

        $access = app(WarehouseAccessService::class);
        $this->assertTrue($access->organizationRestrictsToAssigned($this->organization->id));
        $this->assertSame([], $access->accessibleWarehouseIds($this->unassigned));

        $this->actingAs($this->unassigned)
            ->get(route('locations.index'))
            ->assertOk()
            ->assertDontSee('Alpha Shelf')
            ->assertDontSee('Bravo Shelf');

        $this->actingAs($this->admin)
            ->post(route('warehouses.access-policy'), ['restrict_to_assigned' => false])
            ->assertRedirect();

        $this->assertNull($access->accessibleWarehouseIds($this->unassigned));
    }

    public function test_changing_the_setting_requires_manage_warehouse_users(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('warehouses.access-policy'), ['restrict_to_assigned' => true])
            ->assertForbidden();

        $this->assertFalse(app(WarehouseAccessService::class)->organizationRestrictsToAssigned($this->organization->id));
    }
}
