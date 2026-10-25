<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use App\Mail\ScheduledReportEmail;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\Reports\ScheduledReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ReportAccessBoundariesTest extends TestCase
{
    use RefreshDatabase, WarehouseAccessFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpWarehouseAccess();
        $this->grantReports($this->restricted);
        $this->grantReports($this->unassigned);
        $this->makeAdjustment($this->productA, $this->locationA, 'Alpha visible movement');
        $this->makeAdjustment($this->productB, $this->locationB, 'Bravo private movement');
        $this->makeAdjustment($this->productA, null, 'Unassigned private movement');
    }

    private function grantReports(User $user, array $permissions = ['view_reports']): void
    {
        $role = Role::create([
            'organization_id' => $this->organization->id,
            'name' => 'Reporter', 'slug' => 'reporter-'.uniqid(), 'permissions' => $permissions,
        ]);
        $user->roles()->attach($role->id);
    }

    public static function sourcePermissions(): array
    {
        return [
            'valuation' => ['reports.inventory-valuation', 'view_products'],
            'low stock' => ['reports.low-stock', 'view_products'],
            'categories' => ['reports.category-performance', 'view_products'],
            'sales' => ['reports.sales-analysis', 'view_orders'],
        ];
    }

    #[DataProvider('sourcePermissions')]
    public function test_fixed_pages_and_exports_require_the_source_permission(string $route, string $permission): void
    {
        $reporter = User::factory()->forOrganization($this->organization->id)->create(['role' => 'member']);
        $this->grantReports($reporter);
        foreach ([null, 'csv', 'xlsx', 'pdf'] as $format) {
            $this->actingAs($reporter)->get(route($route, $format ? ['export' => $format] : []))->assertForbidden();
        }
        $this->grantReports($reporter, [$permission]);
        $reporter->unsetRelation('roles');
        $this->actingAs($reporter->fresh())->get(route($route))->assertOk();
    }

    public function test_fixed_movement_rows_totals_and_csv_follow_the_viewers_warehouses(): void
    {
        $this->actingAs($this->restricted)->get(route('reports.stock-movement'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('adjustments.data', 1)
            ->where('adjustments.data.0.reason', 'Alpha visible movement')
            ->where('summary.total_adjustments', 1)
            ->where('summary.net_change', 1));
        $csv = $this->get(route('reports.stock-movement', ['export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Alpha visible movement', $csv);
        $this->assertStringNotContainsString('Bravo private movement', $csv);
        $this->assertStringNotContainsString('Unassigned private movement', $csv);
        $this->actingAs($this->unassigned)->get(route('reports.stock-movement'))
            ->assertInertia(fn (Assert $page) => $page->has('adjustments.data', 3)->where('summary.total_adjustments', 3));
        $this->actingAs($this->admin)->get(route('reports.stock-movement'))
            ->assertInertia(fn (Assert $page) => $page->has('adjustments.data', 3));
    }

    public function test_builder_preview_and_shared_export_use_the_viewers_warehouse_scope(): void
    {
        $this->actingAs($this->restricted)->postJson(route('reports.builder.preview'), [
            'data_source' => 'stock_adjustments', 'columns' => ['reason', 'quantity'],
        ])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'Alpha visible movement');

        $report = $this->movementReport($this->admin);
        $csv = $this->get(route('reports.builder.export', $report))->assertOk()->streamedContent();
        $this->assertStringContainsString('Alpha visible movement', $csv);
        $this->assertStringNotContainsString('Bravo private movement', $csv);
        $this->assertStringNotContainsString('Unassigned private movement', $csv);
    }

    private function movementReport(User $owner): SavedReport
    {
        return SavedReport::create([
            'organization_id' => $this->organization->id, 'created_by' => $owner->id,
            'name' => 'Movement', 'data_source' => 'stock_adjustments',
            'columns' => ['reason', 'quantity'], 'is_shared' => true,
        ]);
    }

    private function schedule(SavedReport $report, array $recipients): ReportSchedule
    {
        return ReportSchedule::create([
            'organization_id' => $this->organization->id, 'saved_report_id' => $report->id,
            'created_by' => $report->created_by, 'frequency' => 'daily', 'time_of_day' => '09:00',
            'format' => 'csv', 'recipients' => $recipients, 'is_active' => true, 'next_run_at' => now(),
        ]);
    }

    public function test_org_wide_scheduled_movements_are_not_mailed_to_warehouse_restricted_recipients(): void
    {
        Mail::fake();
        $emails = [$this->restricted->email, $this->admin->email];
        $schedule = $this->schedule($this->movementReport($this->admin), $emails);
        $this->assertSame('sent', app(ScheduledReportRunner::class)->deliver($schedule->id, $emails));
        Mail::assertSent(ScheduledReportEmail::class, 1);
        Mail::assertSent(ScheduledReportEmail::class, fn ($mail) => $mail->hasTo($this->admin->email));
    }

    public function test_warehouse_scoped_scheduled_movements_stay_scoped_for_unrestricted_recipients(): void
    {
        Mail::fake();
        $emails = [$this->restricted->email, $this->admin->email];
        $schedule = $this->schedule($this->movementReport($this->restricted), $emails);
        $this->assertSame('sent', app(ScheduledReportRunner::class)->deliver($schedule->id, $emails));
        Mail::assertSent(ScheduledReportEmail::class, 2);
        Mail::assertSent(ScheduledReportEmail::class, function ($mail) {
            $this->assertStringContainsString('Alpha visible movement', $mail->attachmentContent());
            $this->assertStringNotContainsString('Bravo private movement', $mail->attachmentContent());
            $this->assertStringNotContainsString('Unassigned private movement', $mail->attachmentContent());

            return true;
        });
    }
}
