<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessProductImportJob;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductCategory;
use App\Models\Inventory\ProductLocation;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P2-12 (imports) — imports run inline when small, queue + notify when large.
 */
class QueuedImportTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $importer;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Import Org', 'email' => 'imp@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);

        ProductCategory::create([
            'organization_id' => $this->organization->id, 'name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true,
        ]);
        ProductLocation::create([
            'organization_id' => $this->organization->id, 'name' => 'Warehouse A', 'code' => 'WH-A', 'is_active' => true,
        ]);

        $this->importer = User::create([
            'name' => 'Importer', 'email' => 'importer@org.com', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);

        $role = Role::firstOrCreate(
            ['slug' => 'import-only'],
            ['name' => 'Import', 'is_system' => true, 'permissions' => ['import_data']],
        );
        $this->importer->roles()->syncWithoutDetaching([$role->id]);
    }

    private function csv(): UploadedFile
    {
        $content = "name,sku,barcode,description,category,location,price,currency,purchase_price,stock,min_stock,status,notes\n";
        $content .= "Queued Product,QImp-001,111,Desc,Electronics,Warehouse A,9.99,USD,5.00,20,2,active,note\n";

        return UploadedFile::fake()->createWithContent('products.csv', $content);
    }

    public function test_small_import_runs_synchronously(): void
    {
        config(['imports.sync_max_kb' => 1024]);
        Queue::fake();

        $this->actingAs($this->importer)
            ->post(route('import-export.import-products'), ['file' => $this->csv()])
            ->assertRedirect(route('import-export.index'));

        // Processed inline: product exists now, nothing queued.
        $this->assertDatabaseHas('products', ['sku' => 'QImp-001', 'organization_id' => $this->organization->id]);
        Queue::assertNothingPushed();
    }

    public function test_large_import_is_queued(): void
    {
        config(['imports.sync_max_kb' => 0, 'imports.disk' => 'local']);
        Queue::fake();
        Storage::fake('local');

        $this->actingAs($this->importer)
            ->post(route('import-export.import-products'), ['file' => $this->csv()])
            ->assertRedirect(route('import-export.index'))
            ->assertSessionHas('success');

        // Deferred: not imported inline; the job is queued instead.
        $this->assertDatabaseMissing('products', ['sku' => 'QImp-001']);
        Queue::assertPushed(ProcessProductImportJob::class, 1);
    }

    public function test_import_job_processes_file_and_notifies(): void
    {
        Storage::fake('local');
        $path = 'imports/'.$this->organization->id.'/file.csv';
        Storage::disk('local')->put($path, $this->csv()->getContent());

        (new ProcessProductImportJob($this->organization->id, $this->importer->id, 'local', $path))->handle();

        $this->assertDatabaseHas('products', ['sku' => 'QImp-001', 'organization_id' => $this->organization->id]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->importer->id,
            'type' => 'import_complete',
        ]);
        // Temp upload is cleaned up.
        Storage::disk('local')->assertMissing($path);
    }
}
