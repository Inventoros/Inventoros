<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Marketplace\MarketplaceClient;
use App\Services\Marketplace\MarketplaceException;
use App\Services\Update\FileUpdateService;
use Exception;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The updater and the marketplace client both decide where to connect by
 * looking at a URL's host. A host written in a noncanonical form (trailing
 * dot, userinfo, backslash, percent-escapes, non-ASCII lookalikes) can be
 * read one way by parse_url() and another way by the HTTP client, which is
 * how host allowlists get bypassed. Such hosts are refused outright, before
 * any request is sent.
 */
final class NoncanonicalHostAllowlistTest extends TestCase
{
    private const ASSET = 'https://github.com/Inventoros/Inventoros/releases/download/v2.0.0/inventoros-cpanel-2.0.0.zip';

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

    /**
     * @return array<string, array{string}>
     */
    public static function noncanonicalRedirects(): array
    {
        return [
            'trailing dot on an allowlisted host' => ['https://release-assets.githubusercontent.com./x.zip'],
            'trailing dot on a foreign host' => ['https://evil.test./x.zip'],
            'allowlisted name as a subdomain prefix' => ['https://github.com.evil.test/x.zip'],
            'uppercase foreign host' => ['https://GITHUB.COM.EVIL.TEST/x.zip'],
            'userinfo naming an allowlisted host' => ['https://github.com@evil.test/x.zip'],
            'userinfo with a port-like password' => ['https://github.com:443@evil.test/x.zip'],
            'backslash before userinfo' => ['https://evil.test\\@github.com/x.zip'],
            'percent-escaped userinfo' => ['https://evil.test%2f@github.com/x.zip'],
            'percent-escaped dot in the host' => ['https://github%2ecom/x.zip'],
            'IDN lookalike (Cyrillic i)' => ["https://g\u{0456}thub.com/x.zip"],
            'fullwidth dot' => ["https://github\u{FF0E}com/x.zip"],
            'fragment hiding the real host' => ['https://evil.test#@github.com/x.zip'],
            'embedded whitespace' => ['https://github.com /x.zip'],
        ];
    }

    #[DataProvider('noncanonicalRedirects')]
    public function test_the_updater_refuses_a_redirect_to_a_noncanonical_host(string $location): void
    {
        Http::fake([
            self::ASSET => Http::response('', 302, ['Location' => $location]),
            '*' => Http::response('PWNED', 200),
        ]);

        try {
            $this->downloaded[] = (new FileUpdateService)->downloadRelease(self::ASSET);
            $this->fail("A redirect to {$location} must be refused.");
        } catch (Exception $e) {
            $this->assertStringContainsString('allowlist', $e->getMessage());
        }

        // Only the first, allowlisted request ever went out.
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->url() !== self::ASSET);
    }

    public function test_the_updater_refuses_a_noncanonical_starting_url_even_when_the_prefix_matches(): void
    {
        config(['update.download_url_prefixes' => ['https://github.com']]);

        Http::fake(['*' => Http::response('PWNED', 200)]);

        try {
            $this->downloaded[] = (new FileUpdateService)->downloadRelease('https://github.com@evil.test/x.zip');
            $this->fail('A userinfo URL must be refused even when it starts with an allowed prefix.');
        } catch (Exception $e) {
            $this->assertStringContainsString('allowlist', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_updater_treats_host_case_as_insignificant_for_allowlisted_hosts(): void
    {
        Http::fake([
            self::ASSET => Http::response('', 302, ['Location' => 'https://Release-Assets.GitHubUserContent.com/x.zip']),
            'release-assets.githubusercontent.com/*' => Http::response('ZIP-BYTES', 200),
        ]);

        $path = $this->downloaded[] = (new FileUpdateService)->downloadRelease(self::ASSET);

        $this->assertSame('ZIP-BYTES', File::get($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noncanonicalMarketplaceOrigins(): array
    {
        return [
            'trailing dot' => ['https://marketplace.test.'],
            'userinfo' => ['https://marketplace.test@evil.test'],
            'backslash before userinfo' => ['https://evil.test\\@marketplace.test'],
            'percent-escaped host' => ['https://marketplace%2etest'],
            'IDN lookalike' => ["https://m\u{0430}rketplace.test"],
            'empty label' => ['https://marketplace..test'],
            'fragment hiding the real host' => ['https://evil.test#@marketplace.test'],
        ];
    }

    #[DataProvider('noncanonicalMarketplaceOrigins')]
    public function test_the_marketplace_client_refuses_a_noncanonical_origin(string $url): void
    {
        config(['marketplace.url' => $url, 'marketplace.cache_seconds' => 0]);
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        try {
            (new MarketplaceClient)->catalog(null);
            $this->fail("The marketplace origin {$url} must be refused.");
        } catch (MarketplaceException $e) {
            $this->assertStringContainsString('INVENTOROS_MARKETPLACE_URL', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_marketplace_client_normalises_host_case(): void
    {
        config(['marketplace.url' => 'https://MarketPlace.TEST']);

        $this->assertSame('https://marketplace.test', (new MarketplaceClient)->origin());
    }
}
