<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessProductImportJob;
use App\Models\Auth\Organization;
use App\Models\Notification;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Import warnings (duplicate-SKU skips, unknown suppliers, ...) are not
 * failures, but the user still has to see them. A successful import used to
 * flash a plain success string and drop the warnings on the floor.
 */
final class ImportResultFlashTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    public function test_a_successful_import_with_warnings_flashes_the_warnings(): void
    {
        $csv = "name,sku,price,stock\nFirst,DUP-1,5,1\nSecond,DUP-1,6,2\n";

        $response = $this->actingAs($this->admin)->post(route('import-export.import-products'), [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
        ]);

        $response->assertRedirect(route('import-export.index'));
        $flash = session('warning');
        $this->assertIsArray($flash);
        $this->assertSame('products', $flash['type']);
        $this->assertSame(1, $flash['stats']['imported']);
        $this->assertSame([], $flash['stats']['errors']);
        $this->assertCount(1, $flash['stats']['warnings']);
        $this->assertSame(3, $flash['stats']['warnings'][0]['row']);
        $this->assertStringContainsString('warning', strtolower($flash['message']));
    }

    public function test_a_clean_import_still_flashes_a_plain_success_string(): void
    {
        $csv = "name,sku,price,stock\nFirst,OK-1,5,1\n";

        $this->actingAs($this->admin)->post(route('import-export.import-products'), [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
        ]);

        $this->assertIsString(session('success'));
        $this->assertNull(session('warning'));
    }

    public function test_the_queued_import_notification_counts_warnings(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('imports/dup.csv', "sku,name,price,stock\nD-1,A,5,1\nD-1,B,5,1\n");

        (new ProcessProductImportJob($this->org->id, $this->admin->id, 'local', 'imports/dup.csv'))->handle();

        $notification = Notification::where('user_id', $this->admin->id)->where('type', 'import_complete')->sole();
        $this->assertStringContainsString('1 warning', $notification->message);
        $this->assertSame(1, $notification->data['warning_count']);
    }
}
