<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Role;
use App\Models\SavedReport;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Saved (builder) reports export as CSV (unchanged default), XLSX and PDF,
 * with the per-source permission re-checked for the downloading user.
 */
class ReportBuilderExportFormatsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private SavedReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.test', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'owner@org.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        Product::create([
            'organization_id' => $this->org->id, 'name' => '@SUM(A1)', 'sku' => 'BX-1',
            'price' => 12.5, 'currency' => 'USD', 'stock' => 3,
        ]);

        $this->report = SavedReport::create([
            'organization_id' => $this->org->id, 'created_by' => $this->owner->id,
            'name' => 'Stock list', 'data_source' => 'products',
            'columns' => ['name', 'sku', 'stock', 'price'], 'is_shared' => true,
        ]);
    }

    public function test_default_export_is_still_csv(): void
    {
        $response = $this->actingAs($this->owner)->get(route('reports.builder.export', $this->report));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString("'@SUM(A1)", $response->streamedContent());
    }

    public function test_xlsx_export_keeps_numbers_numeric_and_neutralises_text(): void
    {
        $response = $this->actingAs($this->owner)->get(route('reports.builder.export', [$this->report, 'format' => 'xlsx']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'bx').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $this->assertSame("'@SUM(A1)", $sheet->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C2')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType());
        $this->assertEquals(12.5, $sheet->getCell('D2')->getValue());
    }

    public function test_pdf_export(): void
    {
        $response = $this->actingAs($this->owner)->get(route('reports.builder.export', [$this->report, 'format' => 'pdf']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    public function test_unknown_format_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->get(route('reports.builder.export', [$this->report, 'format' => 'html']))
            ->assertStatus(422);
    }

    public function test_a_viewer_without_the_source_permission_cannot_export_a_shared_report(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@org.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $role = Role::create(['name' => 'R', 'slug' => 'r-bx', 'is_system' => false, 'permissions' => ['view_reports']]);
        $viewer->roles()->syncWithoutDetaching([$role->id]);

        $this->actingAs($viewer)
            ->get(route('reports.builder.export', [$this->report, 'format' => 'xlsx']))
            ->assertForbidden();
    }
}
