<?php

namespace Tests\Feature\Security;

use App\Services\Update\FileUpdateService;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The update download URL is parsed before it is compared with the
 * allowlisted prefixes: https only, the prefix's exact host, and a path
 * that cannot be normalised to somewhere outside the prefix.
 */
final class UpdateDownloadUrlAllowlistTest extends TestCase
{
    private const PREFIX = 'https://github.com/Inventoros/Inventoros/releases/download/';

    /** @var array<int, string> */
    private array $downloaded = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['update.download_url_prefixes' => [self::PREFIX]]);
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
    public static function refusedUrls(): array
    {
        return [
            'dot-dot segment' => [self::PREFIX.'../../../evil/repo/releases/download/v1/x.zip'],
            'dot-dot inside segment' => [self::PREFIX.'v1/..%2F../x.zip'],
            'encoded dot lower' => [self::PREFIX.'%2e%2e/%2e%2e/%2e%2e/evil/x.zip'],
            'encoded dot upper' => [self::PREFIX.'%2E%2E/%2E%2E/evil/x.zip'],
            'mixed encoded dot' => [self::PREFIX.'.%2e/.%2E/evil/x.zip'],
            'encoded slash' => [self::PREFIX.'v1%2F..%2F..%2Fx.zip'],
            'backslash' => [self::PREFIX.'v1\\..\\..\\x.zip'],
            'encoded backslash' => [self::PREFIX.'v1%5c..%5cx.zip'],
            'plain http' => ['http://github.com/Inventoros/Inventoros/releases/download/v1/x.zip'],
            'other host with prefix as path' => ['https://evil.test/github.com/Inventoros/Inventoros/releases/download/v1/x.zip'],
            'userinfo' => ['https://user@github.com/Inventoros/Inventoros/releases/download/v1/x.zip'],
            'explicit port' => ['https://github.com:8443/Inventoros/Inventoros/releases/download/v1/x.zip'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_a_download_url_that_does_not_stay_under_the_prefix_is_refused(string $url): void
    {
        Http::fake(['*' => Http::response('PWNED', 200)]);

        try {
            $this->downloaded[] = (new FileUpdateService)->downloadRelease($url);
            $this->fail("{$url} must be refused.");
        } catch (Exception $e) {
            $this->assertStringContainsString('not in the allowed list', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_prefix_without_a_trailing_slash_does_not_match_a_longer_host(): void
    {
        config(['update.download_url_prefixes' => ['https://github.com']]);
        Http::fake(['*' => Http::response('PWNED', 200)]);

        try {
            $this->downloaded[] = (new FileUpdateService)->downloadRelease('https://github.com.evil.test/x.zip');
            $this->fail('A different host must be refused even when it starts with the prefix text.');
        } catch (Exception $e) {
            $this->assertStringContainsString('not in the allowed list', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_legitimate_github_release_url_is_downloaded(): void
    {
        $url = self::PREFIX.'v2.1.0/inventoros-cpanel-2.1.0.zip';
        Http::fake([$url => Http::response('ZIP-BYTES', 200)]);

        $path = $this->downloaded[] = (new FileUpdateService)->downloadRelease($url);

        $this->assertSame('ZIP-BYTES', File::get($path));
    }

    public function test_the_host_comparison_ignores_case(): void
    {
        $url = 'https://GitHub.com/Inventoros/Inventoros/releases/download/v2.1.0/inventoros-cpanel-2.1.0.zip';
        Http::fake(['*' => Http::response('ZIP-BYTES', 200)]);

        $path = $this->downloaded[] = (new FileUpdateService)->downloadRelease($url);

        $this->assertSame('ZIP-BYTES', File::get($path));
    }
}
