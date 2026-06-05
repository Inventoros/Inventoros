<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessProductImportJob;
use App\Models\Auth\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProcessProductImportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_configured_to_retry(): void
    {
        $job = new ProcessProductImportJob(1, 1, 'local', 'imports/x.csv');
        $this->assertSame(3, $job->tries);
    }

    public function test_backoff_returns_delay_array(): void
    {
        $job = new ProcessProductImportJob(1, 1, 'local', 'imports/x.csv');
        $backoff = $job->backoff();
        $this->assertIsArray($backoff);
        $this->assertNotEmpty($backoff);
    }

    public function test_successful_import_upserts_and_deletes_upload(): void
    {
        Notification::fake();
        Storage::fake('local');

        $org = Organization::create([
            'name' => 'Imp Org', 'email' => 'imp@org.com', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $user = User::create([
            'name' => 'Importer', 'email' => 'importer@test.com', 'password' => bcrypt('password'),
            'organization_id' => $org->id, 'role' => 'admin',
        ]);

        Storage::disk('local')->put('imports/test.csv', "sku,name,price,stock\nIMP-1,Widget,5,10\n");

        (new ProcessProductImportJob($org->id, $user->id, 'local', 'imports/test.csv'))->handle();

        $this->assertDatabaseHas('products', ['sku' => 'IMP-1', 'organization_id' => $org->id]);
        Storage::disk('local')->assertMissing('imports/test.csv'); // deleted on success
    }
}
