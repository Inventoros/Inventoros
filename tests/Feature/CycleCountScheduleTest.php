<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\CycleCountSchedule;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\Notification;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CycleCountService;
use App\Services\Organizations\OrganizationMembershipService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CycleCountScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $manager;

    private User $counter;

    private User $viewer;

    private ProductLocation $aisleA;

    private ProductLocation $aisleB;

    private ProductCategory $bolts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        SystemSetting::set('installed', true, 'boolean');
        Carbon::setTestNow('2026-09-28 08:00:00');

        $this->org = Organization::create(['name' => 'Acme', 'email' => 'ops@acme.test', 'currency' => 'USD', 'timezone' => 'UTC']);

        $manage = Role::create([
            'slug' => 'auditor', 'name' => 'Auditor', 'is_system' => false, 'organization_id' => $this->org->id,
            'permissions' => ['view_products', 'view_stock_audits', 'create_stock_audits', 'manage_stock_audits'],
        ]);
        $view = Role::create([
            'slug' => 'audit-viewer', 'name' => 'Audit viewer', 'is_system' => false, 'organization_id' => $this->org->id,
            'permissions' => ['view_stock_audits'],
        ]);

        $this->manager = $this->user('Mia Manager', 'mia@acme.test', $manage);
        $this->counter = $this->user('Cal Counter', 'cal@acme.test', $manage);
        $this->viewer = $this->user('Val Viewer', 'val@acme.test', $view);

        $warehouse = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Main', 'code' => 'MAIN']);
        $this->aisleA = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'Aisle A', 'code' => 'A', 'is_active' => true, 'warehouse_id' => $warehouse->id]);
        $this->aisleB = ProductLocation::create(['organization_id' => $this->org->id, 'name' => 'Aisle B', 'code' => 'B', 'is_active' => true]);
        $this->bolts = ProductCategory::create(['organization_id' => $this->org->id, 'name' => 'Bolts', 'slug' => 'bolts', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, string $email, Role $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('x'), 'organization_id' => $this->org->id, 'role' => 'member']);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->org->id, 'sku' => $sku, 'name' => "Product {$sku}",
            'price' => 1, 'currency' => 'USD', 'stock' => 10, 'is_active' => true,
            'location_id' => $this->aisleA->id,
        ], $attributes));
    }

    /**
     * Record that $product was counted at $when in a completed audit.
     */
    private function countedAt(Product $product, string $when): void
    {
        $audit = StockAudit::create([
            'organization_id' => $this->org->id, 'audit_number' => 'SA-H'.$product->id.'-'.md5($when),
            'name' => 'History', 'status' => 'completed', 'audit_type' => 'cycle', 'created_by' => $this->manager->id,
        ]);
        StockAuditItem::create([
            'stock_audit_id' => $audit->id, 'product_id' => $product->id, 'system_quantity' => 10,
            'counted_quantity' => 10, 'status' => 'adjusted', 'counted_at' => Carbon::parse($when),
        ]);
    }

    private function schedule(array $attributes = []): CycleCountSchedule
    {
        return CycleCountSchedule::create(array_merge([
            'organization_id' => $this->org->id,
            'name' => 'Daily A-count',
            'frequency' => 'daily',
            'scope_type' => 'all',
            'products_per_run' => 2,
            'assigned_to' => $this->counter->id,
            'is_active' => true,
            'next_run_at' => now(),
            'created_by' => $this->manager->id,
        ], $attributes));
    }

    // ==================== DUE LOGIC ====================

    public function test_a_schedule_in_an_organization_with_only_guest_members_still_creates_an_audit(): void
    {
        $company = Organization::create(['name' => 'Guest company', 'currency' => 'USD', 'timezone' => 'UTC', 'is_active' => true]);
        app(OrganizationMembershipService::class)->add($company, $this->manager, 'admin');
        $this->product('GUEST-COUNT', ['organization_id' => $company->id, 'location_id' => null]);
        $schedule = $this->schedule(['organization_id' => $company->id, 'assigned_to' => null]);

        $audit = app(CycleCountService::class)->run($schedule);

        $this->assertNotNull($audit);
        $this->assertSame($company->id, $audit->organization_id);
        $this->assertSame($this->manager->id, $audit->created_by);
        $this->assertSame(1, $audit->items()->count());
        $this->assertSame($this->org->id, $this->manager->fresh()->organization_id);
    }

    public function test_location_cycle_count_draft_snapshots_the_bin_not_the_product_total(): void
    {
        $product = $this->product('BIN-COUNT');
        foreach ([[$this->aisleA, 3], [$this->aisleB, 7]] as [$location, $quantity]) {
            ProductLocationStock::create([
                'organization_id' => $this->org->id, 'product_id' => $product->id,
                'location_id' => $location->id, 'quantity' => $quantity,
            ]);
        }
        $schedule = $this->schedule(['scope_type' => 'location', 'scope_id' => $this->aisleA->id]);

        $audit = app(CycleCountService::class)->run($schedule);

        $this->assertSame(3, $audit->items()->sole()->system_quantity);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_a_schedule_is_due_once_its_next_run_time_has_passed(): void
    {
        $this->assertTrue($this->schedule(['next_run_at' => now()->subMinute()])->isDue());
        $this->assertTrue($this->schedule(['next_run_at' => now()])->isDue());
        $this->assertFalse($this->schedule(['next_run_at' => now()->addMinute()])->isDue());
        $this->assertFalse($this->schedule(['is_active' => false, 'next_run_at' => now()->subDay()])->isDue());
        $this->assertFalse($this->schedule(['next_run_at' => null])->isDue());
    }

    public function test_the_next_run_advances_by_the_frequency_past_now(): void
    {
        $daily = $this->schedule(['frequency' => 'daily', 'next_run_at' => '2026-09-28 06:00:00']);
        $this->assertSame('2026-09-29 06:00:00', $daily->nextRunAfter(now())->toDateTimeString());

        $weekly = $this->schedule(['frequency' => 'weekly', 'next_run_at' => '2026-09-28 06:00:00']);
        $this->assertSame('2026-10-05 06:00:00', $weekly->nextRunAfter(now())->toDateTimeString());

        $monthly = $this->schedule(['frequency' => 'monthly', 'next_run_at' => '2026-08-31 06:00:00']);
        $this->assertSame('2026-09-30 06:00:00', $monthly->nextRunAfter(Carbon::parse('2026-09-01'))->toDateTimeString(), 'Month ends do not overflow.');

        // Missed runs are skipped rather than replayed one by one.
        $stale = $this->schedule(['frequency' => 'daily', 'next_run_at' => '2026-09-20 06:00:00']);
        $this->assertSame('2026-09-29 06:00:00', $stale->nextRunAfter(now())->toDateTimeString());
    }

    // ==================== PRODUCT SELECTION ====================

    public function test_it_picks_the_least_recently_counted_products_first(): void
    {
        $recent = $this->product('RECENT');
        $old = $this->product('OLD');
        $never = $this->product('NEVER');
        $middle = $this->product('MIDDLE');

        $this->countedAt($recent, '2026-09-27 10:00:00');
        $this->countedAt($old, '2026-06-01 10:00:00');
        $this->countedAt($middle, '2026-08-01 10:00:00');
        // A later count supersedes an older one for the same product.
        $this->countedAt($old, '2026-05-01 10:00:00');

        $picked = app(CycleCountService::class)->selectProducts($this->schedule(['products_per_run' => 3]));

        $this->assertSame(['NEVER', 'OLD', 'MIDDLE'], $picked->pluck('sku')->all());
    }

    public function test_it_only_picks_active_products_in_scope_and_in_the_organization(): void
    {
        $inA = $this->product('IN-A');
        $this->product('IN-B', ['location_id' => $this->aisleB->id]);
        $this->product('INACTIVE', ['is_active' => false]);
        $bolt = $this->product('BOLT', ['location_id' => $this->aisleB->id, 'category_id' => $this->bolts->id]);

        $other = Organization::create(['name' => 'Other', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        Product::create(['organization_id' => $other->id, 'sku' => 'FOREIGN', 'name' => 'Foreign', 'price' => 1, 'currency' => 'USD', 'stock' => 1, 'is_active' => true]);

        $service = app(CycleCountService::class);

        $this->assertEqualsCanonicalizing(
            ['IN-A', 'IN-B', 'BOLT'],
            $service->selectProducts($this->schedule(['products_per_run' => 10]))->pluck('sku')->all(),
        );
        $this->assertSame(
            ['IN-A'],
            $service->selectProducts($this->schedule(['scope_type' => 'location', 'scope_id' => $this->aisleA->id, 'products_per_run' => 10]))->pluck('sku')->all(),
        );
        $this->assertSame(
            ['IN-A'],
            $service->selectProducts($this->schedule(['scope_type' => 'warehouse', 'scope_id' => $this->aisleA->warehouse_id, 'products_per_run' => 10]))->pluck('sku')->all(),
        );
        $this->assertSame(
            ['BOLT'],
            $service->selectProducts($this->schedule(['scope_type' => 'category', 'scope_id' => $this->bolts->id, 'products_per_run' => 10]))->pluck('sku')->all(),
        );
        $this->assertCount(1, $service->selectProducts($this->schedule(['products_per_run' => 1])));
        $this->assertTrue($inA->exists && $bolt->exists);
    }

    // ==================== THE SCHEDULED COMMAND ====================

    public function test_the_command_creates_a_draft_cycle_audit_for_each_due_schedule_and_notifies_the_assignee(): void
    {
        $this->product('P1');
        $this->product('P2');
        $this->product('P3');
        $due = $this->schedule(['next_run_at' => '2026-09-28 06:00:00']);
        $notDue = $this->schedule(['name' => 'Later', 'next_run_at' => '2026-09-29 06:00:00']);

        $this->artisan('inventory:run-cycle-counts')->assertSuccessful();

        $audit = StockAudit::where('cycle_count_schedule_id', $due->id)->sole();
        $this->assertSame('draft', $audit->status);
        $this->assertSame('cycle', $audit->audit_type);
        $this->assertSame($this->counter->id, $audit->assigned_to);
        $this->assertSame(2, $audit->items()->count());
        $this->assertStringContainsString('Daily A-count', $audit->name);

        $due->refresh();
        $this->assertSame($audit->id, $due->last_audit_id);
        $this->assertSame('2026-09-28 08:00:00', $due->last_run_at->toDateTimeString());
        $this->assertSame('2026-09-29 06:00:00', $due->next_run_at->toDateTimeString());

        $this->assertSame(0, StockAudit::where('cycle_count_schedule_id', $notDue->id)->count());
        $this->assertTrue(Notification::where('user_id', $this->counter->id)->where('type', 'cycle_count_assigned')->exists());

        // Running again in the same tick does nothing: the schedule is no longer due.
        $this->artisan('inventory:run-cycle-counts')->assertSuccessful();
        $this->assertSame(1, StockAudit::where('cycle_count_schedule_id', $due->id)->count());
    }

    public function test_the_next_run_after_a_previous_count_covers_different_products(): void
    {
        $this->product('P1');
        $this->product('P2');
        $this->product('P3');
        $this->product('P4');
        $schedule = $this->schedule();

        $first = app(CycleCountService::class)->run($schedule);
        $firstSkus = $first->items()->with('product')->get()->pluck('product.sku')->sort()->values()->all();

        // The counter counts and completes the first audit.
        foreach ($first->items as $item) {
            $item->update(['counted_quantity' => 10, 'counted_at' => now(), 'status' => 'counted']);
        }
        $first->update(['status' => 'completed']);

        Carbon::setTestNow('2026-09-29 08:00:00');
        $second = app(CycleCountService::class)->run($schedule->fresh());
        $secondSkus = $second->items()->with('product')->get()->pluck('product.sku')->sort()->values()->all();

        $this->assertSame([], array_values(array_intersect($firstSkus, $secondSkus)));
    }

    public function test_a_schedule_whose_last_audit_is_still_open_skips_a_run_but_moves_on(): void
    {
        $this->product('P1');
        $schedule = $this->schedule();
        $first = app(CycleCountService::class)->run($schedule);
        $this->assertNotNull($first);

        Carbon::setTestNow('2026-09-29 08:00:00');
        $this->assertNull(app(CycleCountService::class)->run($schedule->fresh()));

        $this->assertSame(1, StockAudit::where('cycle_count_schedule_id', $schedule->id)->count());
        $this->assertSame('2026-09-30 08:00:00', $schedule->fresh()->next_run_at->toDateTimeString());
    }

    public function test_inactive_schedules_and_empty_scopes_create_nothing(): void
    {
        $this->schedule(['is_active' => false, 'next_run_at' => now()->subDay()]);
        $empty = $this->schedule(['scope_type' => 'category', 'scope_id' => $this->bolts->id]);

        $this->artisan('inventory:run-cycle-counts')->assertSuccessful();

        $this->assertSame(0, StockAudit::count());
        $this->assertTrue($empty->fresh()->next_run_at->isFuture(), 'An empty run still moves the schedule on.');
    }

    public function test_the_command_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e->command, 'inventory:run-cycle-counts')));
    }

    // ==================== MANAGEMENT UI ====================

    public function test_a_manager_can_create_edit_and_delete_a_schedule(): void
    {
        $this->actingAs($this->manager)
            ->post(route('cycle-counts.store'), [
                'name' => 'Weekly bolts',
                'frequency' => 'weekly',
                'scope_type' => 'category',
                'scope_id' => $this->bolts->id,
                'products_per_run' => 25,
                'assigned_to' => $this->counter->id,
                'is_active' => true,
            ])
            ->assertRedirect(route('cycle-counts.index'));

        $schedule = CycleCountSchedule::sole();
        $this->assertSame('weekly', $schedule->frequency);
        $this->assertSame($this->manager->id, $schedule->created_by);
        $this->assertNotNull($schedule->next_run_at);

        $this->actingAs($this->manager)
            ->put(route('cycle-counts.update', $schedule), [
                'name' => 'Weekly bolts (A)',
                'frequency' => 'monthly',
                'scope_type' => 'location',
                'scope_id' => $this->aisleA->id,
                'products_per_run' => 10,
                'assigned_to' => null,
                'is_active' => false,
            ])
            ->assertRedirect(route('cycle-counts.index'));

        $schedule->refresh();
        $this->assertSame('monthly', $schedule->frequency);
        $this->assertSame('location', $schedule->scope_type);
        $this->assertFalse($schedule->is_active);

        $this->actingAs($this->manager)
            ->delete(route('cycle-counts.destroy', $schedule))
            ->assertRedirect(route('cycle-counts.index'));
        $this->assertSame(0, CycleCountSchedule::count());
    }

    public function test_the_index_lists_schedules_for_viewers_but_only_managers_can_change_them(): void
    {
        $this->schedule();

        $this->actingAs($this->viewer)
            ->get(route('cycle-counts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('StockAudits/CycleCounts/Index')->has('schedules', 1));

        $this->actingAs($this->viewer)
            ->post(route('cycle-counts.store'), ['name' => 'x', 'frequency' => 'daily', 'scope_type' => 'all', 'products_per_run' => 1])
            ->assertForbidden();
    }

    public function test_scope_and_assignee_must_belong_to_the_organization(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'o@o.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $foreignLocation = ProductLocation::create(['organization_id' => $other->id, 'name' => 'X', 'code' => 'X', 'is_active' => true]);
        $foreignUser = User::create(['name' => 'F', 'email' => 'f@o.test', 'password' => bcrypt('x'), 'organization_id' => $other->id, 'role' => 'admin']);

        $this->actingAs($this->manager)
            ->post(route('cycle-counts.store'), [
                'name' => 'Bad', 'frequency' => 'daily', 'scope_type' => 'location', 'scope_id' => $foreignLocation->id,
                'products_per_run' => 5, 'assigned_to' => $foreignUser->id,
            ])
            ->assertSessionHasErrors(['scope_id', 'assigned_to']);

        $this->actingAs($this->manager)
            ->post(route('cycle-counts.store'), [
                'name' => 'Bad', 'frequency' => 'hourly', 'scope_type' => 'location', 'products_per_run' => 0,
            ])
            ->assertSessionHasErrors(['frequency', 'scope_id', 'products_per_run']);

        $this->assertSame(0, CycleCountSchedule::count());
    }

    public function test_run_now_creates_the_audit_immediately(): void
    {
        $this->product('P1');
        $schedule = $this->schedule(['next_run_at' => now()->addWeek()]);

        $this->actingAs($this->manager)
            ->post(route('cycle-counts.run', $schedule))
            ->assertRedirect();

        $audit = StockAudit::where('cycle_count_schedule_id', $schedule->id)->sole();
        $this->assertSame('draft', $audit->status);
        $this->assertSame(now()->addWeek()->toDateTimeString(), $schedule->fresh()->next_run_at->toDateTimeString(), 'Running early does not move the regular schedule.');
    }
}
