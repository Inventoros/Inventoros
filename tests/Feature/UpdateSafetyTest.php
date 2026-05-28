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

    /**
     * Sign $contents with a freshly minted keypair and configure the public
     * key so verifyArchiveSignature() can be exercised end to end.
     *
     * @return array{public: string, sign: callable(string): string}
     */
    private function configureSigningKeypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secret = sodium_crypto_sign_secretkey($keypair);

        config([
            'update.signature.required' => true,
            'update.signature.public_key' => $public,
            'update.signature.asset_suffix' => '.sig',
            'update.download_url_prefixes' => [
                'https://github.com/Inventoros/Inventoros/releases/download/',
            ],
        ]);

        return [
            'public' => $public,
            'sign' => fn (string $contents): string => base64_encode(
                sodium_crypto_sign_detached($contents, $secret)
            ),
        ];
    }

    public function test_verify_archive_signature_accepts_valid_signature(): void
    {
        $keys = $this->configureSigningKeypair();

        $zipPath = $this->makeZip(['app/x.txt' => 'release']);
        $signature = ($keys['sign'])(file_get_contents($zipPath));

        \Illuminate\Support\Facades\Http::fake([
            '*release.zip.sig' => \Illuminate\Support\Facades\Http::response($signature, 200),
        ]);

        $service = new FileUpdateService();
        $service->verifyArchiveSignature(
            $zipPath,
            'https://github.com/Inventoros/Inventoros/releases/download/v1.0.0/release.zip'
        );

        // No exception == verified.
        $this->assertTrue(true);
    }

    public function test_verify_archive_signature_rejects_tampered_archive(): void
    {
        $keys = $this->configureSigningKeypair();

        $zipPath = $this->makeZip(['app/x.txt' => 'release']);
        $signatureForOriginal = ($keys['sign'])(file_get_contents($zipPath));

        // Attacker swaps the archive bytes but keeps the original signature.
        File::put($zipPath, 'tampered-bytes');

        \Illuminate\Support\Facades\Http::fake([
            '*release.zip.sig' => \Illuminate\Support\Facades\Http::response($signatureForOriginal, 200),
        ]);

        $service = new FileUpdateService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed signature verification');

        $service->verifyArchiveSignature(
            $zipPath,
            'https://github.com/Inventoros/Inventoros/releases/download/v1.0.0/release.zip'
        );
    }

    public function test_verify_archive_signature_fails_closed_without_public_key(): void
    {
        config([
            'update.signature.required' => true,
            'update.signature.public_key' => '',
        ]);

        $zipPath = $this->makeZip(['app/x.txt' => 'release']);

        $service = new FileUpdateService();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('no signing public key is configured');

        $service->verifyArchiveSignature(
            $zipPath,
            'https://github.com/Inventoros/Inventoros/releases/download/v1.0.0/release.zip'
        );
    }

    public function test_verify_archive_signature_skipped_when_not_required(): void
    {
        config([
            'update.signature.required' => false,
            'update.signature.public_key' => '',
        ]);

        $zipPath = $this->makeZip(['app/x.txt' => 'release']);

        $service = new FileUpdateService();
        $service->verifyArchiveSignature(
            $zipPath,
            'https://github.com/Inventoros/Inventoros/releases/download/v1.0.0/release.zip'
        );

        // Opt-out path returns without throwing.
        $this->assertTrue(true);
    }

    public function test_sign_command_produces_signature_verifiable_by_verifier(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secret = base64_encode(sodium_crypto_sign_secretkey($keypair));

        $file = tempnam(sys_get_temp_dir(), 'inv-sign-') . '.zip';
        File::put($file, 'archive-bytes');
        $this->tempPaths[] = $file;
        $this->tempPaths[] = $file . '.sig';

        putenv('INVENTOROS_UPDATE_SECRET_KEY=' . $secret);
        $this->artisan('update:sign', ['file' => $file])->assertSuccessful();
        putenv('INVENTOROS_UPDATE_SECRET_KEY');

        $this->assertFileExists($file . '.sig');

        // The verifier accepts the produced signature under the public key.
        \App\Support\ReleaseSignatureVerifier::verify($file, File::get($file . '.sig'), $public);
        $this->assertTrue(true);
    }
}
