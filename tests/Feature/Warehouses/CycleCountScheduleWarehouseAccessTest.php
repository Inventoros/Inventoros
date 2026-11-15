<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Models\Inventory\CycleCountSchedule;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\StockAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Cycle count schedules follow the warehouse restriction: a user assigned to
 * warehouse A manages only schedules scoped to warehouse A or one of its
 * locations. Category and whole-organization scopes count products in every
 * warehouse, so only unrestricted users may manage them.
 */
class CycleCountScheduleWarehouseAccessTest extends TestCase
{
    use RefreshDatabase;
    use WarehouseAccessFixture;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();

        $this->category = ProductCategory::create([
            'organization_id' => $this->organization->id, 'name' => 'Widgets', 'slug' => 'widgets', 'is_active' => true,
        ]);
    }

    private function schedule(string $name, string $scopeType, ?int $scopeId): CycleCountSchedule
    {
        return CycleCountSchedule::create([
            'organization_id' => $this->organization->id,
            'name' => $name,
            'frequency' => 'weekly',
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'products_per_run' => 10,
            'is_active' => true,
            'next_run_at' => now()->addWeek(),
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $scopeType, ?int $scopeId, string $name = 'Weekly count'): array
    {
        return [
            'name' => $name,
            'frequency' => 'weekly',
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'products_per_run' => 10,
            'is_active' => true,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function listedNames(User $user): array
    {
        $names = [];

        $this->actingAs($user)->get(route('cycle-counts.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$names) {
                $names = collect($page->toArray()['props']['schedules'])->pluck('name')->sort()->values()->all();
            });

        return $names;
    }

    private function seedSchedules(): void
    {
        $this->schedule('Alpha warehouse', 'warehouse', $this->warehouseA->id);
        $this->schedule('Alpha shelf', 'location', $this->locationA->id);
        $this->schedule('Bravo warehouse', 'warehouse', $this->warehouseB->id);
        $this->schedule('Bravo shelf', 'location', $this->locationB->id);
        $this->schedule('Widgets', 'category', $this->category->id);
        $this->schedule('Everything', 'all', null);
    }

    public function test_index_only_lists_schedules_in_the_users_warehouses(): void
    {
        $this->seedSchedules();

        $this->assertSame(['Alpha shelf', 'Alpha warehouse'], $this->listedNames($this->restricted));
    }

    public function test_unrestricted_users_still_list_every_schedule(): void
    {
        $this->seedSchedules();
        $everything = ['Alpha shelf', 'Alpha warehouse', 'Bravo shelf', 'Bravo warehouse', 'Everything', 'Widgets'];

        $this->assertSame($everything, $this->listedNames($this->admin));
        $this->assertSame($everything, $this->listedNames($this->unassigned));
    }

    public function test_restricted_user_cannot_create_a_schedule_outside_their_warehouses(): void
    {
        $refused = [
            ['warehouse', $this->warehouseB->id],
            ['location', $this->locationB->id],
            ['category', $this->category->id],
            ['all', null],
        ];

        foreach ($refused as [$scopeType, $scopeId]) {
            $this->actingAs($this->restricted)
                ->post(route('cycle-counts.store'), $this->payload($scopeType, $scopeId))
                ->assertForbidden();
        }

        $this->assertSame(0, CycleCountSchedule::count());
    }

    public function test_restricted_user_can_create_schedules_in_their_own_warehouse(): void
    {
        $this->actingAs($this->restricted)
            ->post(route('cycle-counts.store'), $this->payload('warehouse', $this->warehouseA->id, 'Alpha warehouse'))
            ->assertRedirect(route('cycle-counts.index'));

        $this->actingAs($this->restricted)
            ->post(route('cycle-counts.store'), $this->payload('location', $this->locationA->id, 'Alpha shelf'))
            ->assertRedirect(route('cycle-counts.index'));

        $this->assertSame(2, CycleCountSchedule::count());
    }

    public function test_admin_can_create_category_and_whole_organization_schedules(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cycle-counts.store'), $this->payload('category', $this->category->id, 'Widgets'))
            ->assertRedirect(route('cycle-counts.index'));

        $this->actingAs($this->admin)
            ->post(route('cycle-counts.store'), $this->payload('all', null, 'Everything'))
            ->assertRedirect(route('cycle-counts.index'));

        $this->assertSame(2, CycleCountSchedule::count());
    }

    public function test_restricted_user_cannot_view_edit_or_change_another_warehouses_schedule(): void
    {
        $theirs = $this->schedule('Bravo warehouse', 'warehouse', $this->warehouseB->id);
        $wholeOrg = $this->schedule('Everything', 'all', null);

        foreach ([$theirs, $wholeOrg] as $schedule) {
            $this->actingAs($this->restricted)->get(route('cycle-counts.edit', $schedule))->assertForbidden();

            $this->actingAs($this->restricted)
                ->put(route('cycle-counts.update', $schedule), $this->payload('warehouse', $this->warehouseA->id, 'Hijacked'))
                ->assertForbidden();

            $this->actingAs($this->restricted)->delete(route('cycle-counts.destroy', $schedule))->assertForbidden();
        }

        $this->assertSame('Bravo warehouse', $theirs->fresh()->name);
        $this->assertSame('Everything', $wholeOrg->fresh()->name);
    }

    public function test_restricted_user_cannot_retarget_their_schedule_to_another_warehouse(): void
    {
        $mine = $this->schedule('Alpha warehouse', 'warehouse', $this->warehouseA->id);

        foreach ([['warehouse', $this->warehouseB->id], ['location', $this->locationB->id], ['all', null]] as [$scopeType, $scopeId]) {
            $this->actingAs($this->restricted)
                ->put(route('cycle-counts.update', $mine), $this->payload($scopeType, $scopeId, 'Retargeted'))
                ->assertForbidden();
        }

        $mine->refresh();
        $this->assertSame('Alpha warehouse', $mine->name);
        $this->assertSame('warehouse', $mine->scope_type);
        $this->assertSame($this->warehouseA->id, (int) $mine->scope_id);
    }

    public function test_restricted_user_can_edit_update_and_delete_their_own_schedule(): void
    {
        $mine = $this->schedule('Alpha warehouse', 'warehouse', $this->warehouseA->id);

        $this->actingAs($this->restricted)->get(route('cycle-counts.edit', $mine))->assertOk();

        $this->actingAs($this->restricted)
            ->put(route('cycle-counts.update', $mine), $this->payload('location', $this->locationA->id, 'Alpha shelf'))
            ->assertRedirect(route('cycle-counts.index'));
        $this->assertSame('Alpha shelf', $mine->fresh()->name);

        $this->actingAs($this->restricted)
            ->delete(route('cycle-counts.destroy', $mine))
            ->assertRedirect(route('cycle-counts.index'));
        $this->assertSame(0, CycleCountSchedule::count());
    }

    public function test_restricted_user_cannot_run_another_warehouses_schedule(): void
    {
        $theirs = $this->schedule('Bravo warehouse', 'warehouse', $this->warehouseB->id);
        $wholeOrg = $this->schedule('Everything', 'all', null);

        foreach ([$theirs, $wholeOrg] as $schedule) {
            $this->actingAs($this->restricted)->post(route('cycle-counts.run', $schedule))->assertForbidden();
        }

        $this->assertSame(0, StockAudit::count());
    }

    public function test_restricted_user_can_run_their_own_schedule_and_admin_can_run_any(): void
    {
        $mine = $this->schedule('Alpha warehouse', 'warehouse', $this->warehouseA->id);
        $theirs = $this->schedule('Bravo warehouse', 'warehouse', $this->warehouseB->id);

        $this->actingAs($this->restricted)->post(route('cycle-counts.run', $mine))->assertRedirect();
        $this->assertSame(1, StockAudit::where('cycle_count_schedule_id', $mine->id)->count());

        $this->actingAs($this->admin)->post(route('cycle-counts.run', $theirs))->assertRedirect();
        $this->assertSame(1, StockAudit::where('cycle_count_schedule_id', $theirs->id)->count());
    }
}
