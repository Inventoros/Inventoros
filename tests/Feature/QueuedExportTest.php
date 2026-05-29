<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateDataExportJob;
use App\Models\Auth\Organization;
use App\Models\DataExport;
use App\Models\Inventory\Product;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P2-12 — exports stream synchronously when small, queue + notify when large.
 */
class QueuedExportTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $exporter;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Export Org',
            'email' => 'export@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);

        $this->exporter = User::create([
            'name' => 'Exporter',
            'email' => 'exporter@org.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'role' => 'admin',
        ]);

        $role = Role::firstOrCreate(
            ['slug' => 'export-only'],
            ['name' => 'Export', 'is_system' => true, 'permissions' => ['export_data']],
        );
        $this->exporter->roles()->syncWithoutDetaching([$role->id]);
    }

    private function makeProducts(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Product::create([
                'organization_id' => $this->organization->id,
                'sku' => 'EXP-'.$i,
                'name' => 'Export Product '.$i,
                'price' => 5.00,
                'currency' => 'USD',
                'stock' => 1,
                'min_stock' => 0,
                'is_active' => true,
            ]);
        }
    }

    public function test_small_export_streams_synchronously(): void
    {
        config(['exports.sync_row_limit' => 1000]);
        Queue::fake();
        $this->makeProducts(2);

        $response = $this->actingAs($this->exporter)->get(route('import-export.export-products'));

        $response->assertOk();
        $response->assertDownload();
        $this->assertDatabaseCount('data_exports', 0);
        Queue::assertNothingPushed();
    }

    public function test_large_export_is_queued_and_recorded(): void
    {
        config(['exports.sync_row_limit' => 1]);
        Queue::fake();
        $this->makeProducts(3);

        $response = $this->actingAs($this->exporter)->get(route('import-export.export-products'));

        $response->assertRedirect(route('import-export.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('data_exports', [
            'organization_id' => $this->organization->id,
            'user_id' => $this->exporter->id,
            'type' => 'products',
            'status' => 'pending',
            'row_count' => 3,
        ]);

        Queue::assertPushed(GenerateDataExportJob::class, 1);
    }

    public function test_generate_job_writes_file_and_notifies(): void
    {
        Storage::fake('local');
        config(['exports.sync_row_limit' => 1]);
        $this->makeProducts(2);

        $export = DataExport::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->exporter->id,
            'type' => 'products',
            'filename' => 'products_test.xlsx',
            'disk' => 'local',
            'path' => 'exports/'.$this->organization->id.'/test.xlsx',
            'filters' => [],
            'status' => 'pending',
            'row_count' => 2,
        ]);

        (new GenerateDataExportJob($export->id))->handle();

        Storage::disk('local')->assertExists($export->path);

        $export->refresh();
        $this->assertSame('completed', $export->status);
        $this->assertNotNull($export->completed_at);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->exporter->id,
            'type' => 'export_ready',
        ]);
    }

    public function test_failed_job_marks_record_and_notifies(): void
    {
        $export = DataExport::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->exporter->id,
            'type' => 'products',
            'filename' => 'products_test.xlsx',
            'disk' => 'local',
            'path' => 'exports/x.xlsx',
            'filters' => [],
            'status' => 'processing',
            'row_count' => 2,
        ]);

        (new GenerateDataExportJob($export->id))->failed(new \RuntimeException('boom'));

        $export->refresh();
        $this->assertSame('failed', $export->status);
        $this->assertStringContainsString('boom', (string) $export->error);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->exporter->id,
            'type' => 'export_failed',
        ]);
    }

    public function test_download_streams_completed_export(): void
    {
        Storage::fake('local');
        $path = 'exports/'.$this->organization->id.'/ready.xlsx';
        Storage::disk('local')->put($path, 'fake-xlsx-bytes');

        $export = DataExport::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->exporter->id,
            'type' => 'products',
            'filename' => 'products_ready.xlsx',
            'disk' => 'local',
            'path' => $path,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->exporter)->get(route('import-export.download', $export));

        $response->assertOk();
        $response->assertDownload('products_ready.xlsx');
    }

    public function test_download_404_when_not_ready(): void
    {
        $export = DataExport::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->exporter->id,
            'type' => 'products',
            'filename' => 'pending.xlsx',
            'disk' => 'local',
            'path' => 'exports/pending.xlsx',
            'status' => 'pending',
        ]);

        $this->actingAs($this->exporter)
            ->get(route('import-export.download', $export))
            ->assertNotFound();
    }

    public function test_cannot_download_another_organizations_export(): void
    {
        Storage::fake('local');
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'email' => 'other@org.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@user.com',
            'password' => bcrypt('password'),
            'organization_id' => $otherOrg->id,
            'role' => 'admin',
        ]);

        $path = 'exports/'.$otherOrg->id.'/secret.xlsx';
        Storage::disk('local')->put($path, 'secret');

        $export = DataExport::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'type' => 'products',
            'filename' => 'secret.xlsx',
            'disk' => 'local',
            'path' => $path,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Authenticated as a user in a different organization → org scope 404s.
        $this->actingAs($this->exporter)
            ->get(route('import-export.download', $export))
            ->assertNotFound();
    }
}
