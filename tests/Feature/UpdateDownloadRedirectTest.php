<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Update\FileUpdateService;
use Exception;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub answers every release asset download with a 302 to a signed
 * storage URL, so the updater must follow redirects, but only one hop at a
 * time, over https, to allowlisted hosts, with a bounded size.
 */
final class UpdateDownloadRedirectTest extends TestCase
{
    private const ASSET = 'https://github.com/Inventoros/Inventoros/releases/download/v2.0.0/inventoros-cpanel-2.0.0.zip';

    private const STORAGE = 'https://release-assets.githubusercontent.com/github-production-release-asset/1/abc?sp=r&sig=x';

    /** @var array<int, string> */
    private array $downloaded = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'update.download_url_prefixes' => ['https://github.com/Inventoros/Inventoros/releases/download/'],
            'update.download_hosts' => [
                'github.com',
                'objects.githubusercontent.com',
                'release-assets.githubusercontent.com',
                'codeload.github.com',
            ],
            'update.max_redirects' => 5,
            'update.max_download_bytes' => 1024 * 1024,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->downloaded as $path) {
            File::delete($path);
        }

        parent::tearDown();
    }

    public function test_follows_the_github_redirect_to_release_asset_storage(): void
    {
        Http::fake([
            self::ASSET => Http::response('', 302, ['Location' => self::STORAGE]),
            'release-assets.githubusercontent.com/*' => Http::response('ZIP-BYTES', 200),
        ]);

        $path = $this->downloaded[] = (new FileUpdateService)->downloadRelease(self::ASSET);

        $this->assertSame('ZIP-BYTES', File::get($path));
        Http::assertSentCount(2);
    }

    public function test_follows_a_multi_hop_chain_within_the_allowlist(): void
    {
        Http::fake([
            self::ASSET => Http::response('', 301, ['Location' => 'https://objects.githubusercontent.com/a']),
            'objects.githubusercontent.com/a' => Http::response('', 307, ['Location' => self::STORAGE]),
            'release-assets.githubusercontent.com/*' => Http::response('ZIP-BYTES', 200),
        ]);

        $path = $this->downloaded[] = (new FileUpdateService)->downloadRelease(self::ASSET);

        $this->assertSame('ZIP-BYTES', File::get($path));
        Http::assertSentCount(3);
    }

    public function test_rejects_a_redirect_to_a_host_outside_the_allowlist(): void
    {
        Http::fake([
            self::ASSET => Http::response('', 302, ['Location' => 'https://evil.example.com/payload.zip']),
            'evil.example.com/*' => Http::response('PWNED', 200),
        ]);

        try {
            (new FileUpdateService)->downloadRelease(self::ASSET);
            $this->fail('A redirect to a non-allowlisted host must be refused.');
        } catch (Exception $e) {
            $this->assertStringContainsString('evil.example.com', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example.com'));
    }

    public function test_rejects_a_downgrade_to_plain_http(): void
    {
        Http::fake([
            self::ASSET => Http::response('', 302, ['Location' => 'http://release-assets.githubusercontent.com/x']),
            'release-assets.githubusercontent.com/*' => Http::response('ZIP-BYTES', 200),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('https');

        (new FileUpdateService)->downloadRelease(self::ASSET);
    }

    public function test_gives_up_after_too_many_redirects(): void
    {
        config(['update.max_redirects' => 3]);

        Http::fake([
            'github.com/*' => Http::response('', 302, ['Location' => self::ASSET]),
        ]);

        try {
            (new FileUpdateService)->downloadRelease(self::ASSET);
            $this->fail('A redirect loop must be cut off.');
        } catch (Exception $e) {
            $this->assertStringContainsString('redirect', strtolower($e->getMessage()));
        }

        // The first request plus at most max_redirects hops.
        Http::assertSentCount(4);
    }

    public function test_resolves_a_relative_location_against_the_current_url(): void
    {
        Http::fake([
            self::ASSET => Http::response('', 302, ['Location' => '/Inventoros/Inventoros/releases/download/v2.0.0/real.zip']),
            'github.com/Inventoros/Inventoros/releases/download/v2.0.0/real.zip' => Http::response('ZIP-BYTES', 200),
        ]);

        $path = $this->downloaded[] = (new FileUpdateService)->downloadRelease(self::ASSET);

        $this->assertSame('ZIP-BYTES', File::get($path));
    }

    public function test_refuses_a_download_larger_than_the_size_cap_and_leaves_no_file(): void
    {
        config(['update.max_download_bytes' => 1000]);

        Http::fake([
            self::ASSET => Http::response('', 302, ['Location' => self::STORAGE]),
            // No Content-Length hint: the cap must hold while streaming.
            'release-assets.githubusercontent.com/*' => Http::response(str_repeat('A', 5000), 200),
        ]);

        $before = glob(storage_path('app/temp/update_*.zip')) ?: [];

        try {
            (new FileUpdateService)->downloadRelease(self::ASSET);
            $this->fail('An oversized download must be refused.');
        } catch (Exception $e) {
            $this->assertStringContainsString('size', strtolower($e->getMessage()));
        }

        $this->assertSame($before, glob(storage_path('app/temp/update_*.zip')) ?: []);
    }

    public function test_the_signature_download_follows_the_same_redirect_rules(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        config([
            'update.signature.required' => true,
            'update.signature.public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        ]);

        $zip = tempnam(sys_get_temp_dir(), 'inv-sig');
        File::put($zip, 'ARCHIVE');
        $this->downloaded[] = $zip;
        $signature = base64_encode(sodium_crypto_sign_detached('ARCHIVE', sodium_crypto_sign_secretkey($keypair)));

        Http::fake([
            self::ASSET.'.sig' => Http::response('', 302, ['Location' => self::STORAGE]),
            'release-assets.githubusercontent.com/*' => Http::response($signature, 200),
        ]);

        (new FileUpdateService)->verifyArchiveSignature($zip, self::ASSET);

        Http::assertSentCount(2);
    }
}
