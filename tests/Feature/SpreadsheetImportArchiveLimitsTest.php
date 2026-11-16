<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessProductImportJob;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Support\SafeZipExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * An XLSX upload is a ZIP of XML parts that the spreadsheet reader inflates
 * and parses in memory. Only the compressed upload size was limited, so a
 * small, highly compressed workbook could exhaust a web or queue worker.
 * The archive is now inspected (entry count, total uncompressed size and
 * compression ratio) before any parsing.
 */
class SpreadsheetImportArchiveLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');

        $this->organization = Organization::create([
            'name' => 'Import Org', 'email' => 'import@org.test', 'currency' => 'USD', 'timezone' => 'UTC',
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@import.test', 'password' => bcrypt('password'),
            'organization_id' => $this->organization->id, 'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function tempPath(string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'inventoros-import-').$suffix;
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * A genuine product workbook. $padding adds a second sheet of distinct,
     * highly compressible strings totalling about that many bytes (a cell
     * holds at most 32,767 characters).
     */
    private function productWorkbook(int $padding = 0): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['name', 'sku', 'price', 'stock', 'min_stock', 'status'],
            ['Spreadsheet Product', 'XLSX-001', '9.99', '5', '1', 'active'],
        ]);
        if ($padding > 0) {
            $notes = $book->createSheet()->setTitle('Notes');
            for ($row = 1; $row <= (int) ceil($padding / 30000); $row++) {
                $notes->setCellValue("A{$row}", $row.str_repeat('x', 30000));
            }
        }

        $path = $this->tempPath('.xlsx');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private function upload(string $path): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.xlsx', (string) file_get_contents($path));
    }

    public function test_a_normal_xlsx_still_imports(): void
    {
        $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), ['file' => $this->upload($this->productWorkbook())])
            ->assertRedirect(route('import-export.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('products', ['sku' => 'XLSX-001', 'organization_id' => $this->organization->id]);
    }

    public function test_an_xlsx_over_the_uncompressed_size_cap_is_refused_before_parsing(): void
    {
        config(['imports.xlsx.max_uncompressed_bytes' => 100 * 1024]);

        $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), ['file' => $this->upload($this->productWorkbook(300 * 1024))])
            ->assertRedirect(route('import-export.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_an_xlsx_with_an_extreme_compression_ratio_is_refused(): void
    {
        config(['imports.xlsx.max_compression_ratio' => 50, 'imports.xlsx.ratio_min_bytes' => 64 * 1024]);

        $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), ['file' => $this->upload($this->productWorkbook(300 * 1024))])
            ->assertSessionHas('error');

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_an_oversized_xlsx_is_not_queued(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['imports.sync_max_kb' => 0, 'imports.xlsx.max_uncompressed_bytes' => 100 * 1024]);

        $this->actingAs($this->admin)
            ->post(route('import-export.import-products'), ['file' => $this->upload($this->productWorkbook(300 * 1024))])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_an_xlsx_with_too_many_entries_is_refused(): void
    {
        $path = $this->tempPath('.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        for ($i = 0; $i < 10; $i++) {
            $zip->addFromString("part{$i}.xml", '<a/>');
        }
        $zip->close();

        $this->expectException(RuntimeException::class);
        SafeZipExtractor::inspect($path, ['max_entries' => 5]);
    }

    public function test_a_queued_import_of_an_unreadable_workbook_fails_without_retrying(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('imports/1/broken.xlsx', 'this is not a workbook');

        $job = (new ProcessProductImportJob($this->organization->id, $this->admin->id, 'local', 'imports/1/broken.xlsx', Excel::XLSX))
            ->withFakeQueueInteractions();
        $job->handle();

        $job->assertFailed();
    }
}
