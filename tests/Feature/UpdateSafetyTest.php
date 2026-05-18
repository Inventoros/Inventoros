<?php

namespace Tests\Feature;

use App\Services\Update\FileUpdateService;
use App\Services\UpdateService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class UpdateSafetyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Files / directories created during the test that should be scrubbed.
     *
     * @var string[]
     */
    protected array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_dir($path)) {
                File::deleteDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempPaths = [];

        parent::tearDown();
    }

    protected function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'inv-zip-') . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $this->tempPaths[] = $path;
        return $path;
    }

    public function test_download_release_rejects_non_allowlisted_url(): void
    {
        config(['update.download_url_prefixes' => [
            'https://github.com/Inventoros/Inventoros/releases/download/',
        ]]);

        $service = new FileUpdateService();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not in the allowed list');

        $service->downloadRelease('https://evil.example.com/payload.zip');
    }

    public function test_download_release_accepts_allowlisted_prefix(): void
    {
        // Only verify the URL passes the allowlist check; we don't actually
        // want to hit the network in tests. The reachable behavior is that
        // assertAllowedDownloadUrl() returns silently for an allowed URL and
        // the subsequent Http::get fails (caught by Laravel's HttpClient
        // and translated to "Failed to download release").
        config(['update.download_url_prefixes' => [
            'https://github.com/Inventoros/Inventoros/releases/download/',
        ]]);

        \Illuminate\Support\Facades\Http::fake([
            'github.com/*' => \Illuminate\Support\Facades\Http::response('not-a-real-zip', 200),
        ]);

        $service = new FileUpdateService();
        $zipPath = $service->downloadRelease(
            'https://github.com/Inventoros/Inventoros/releases/download/v9.9.9/release.zip'
        );

        $this->tempPaths[] = $zipPath;
        $this->assertFileExists($zipPath);
    }

    public function test_extract_zip_rejects_zipslip_entry(): void
    {
        $zipPath = $this->makeZip([
            'good/inner.txt' => 'ok',
            'good/../../../escape.txt' => 'pwned',
        ]);

        $service = new FileUpdateService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe path entry');

        $service->extractZip($zipPath);
    }

    public function test_restore_from_backup_rejects_zipslip_entry(): void
    {
        $zipPath = $this->makeZip([
            'app/good.txt' => 'ok',
            '../../escape.txt' => 'pwned',
        ]);

        $service = app(UpdateService::class);
        $result = $service->restoreFromBackup($zipPath);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unsafe path entry', $result['error'] ?? $result['message']);
    }
}
