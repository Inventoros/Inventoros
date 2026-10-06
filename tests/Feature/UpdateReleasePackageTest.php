<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Update\BackupService;
use App\Services\Update\FileUpdateService;
use App\Services\Update\GitHubReleaseService;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * End to end: a cPanel-layout release (inventoros/ + public_html/ +
 * release.json, signed with a throwaway key) is fetched through faked GitHub
 * endpoints and installed over a v1.0.8-style split install living in a
 * fixture directory.
 */
final class UpdateReleasePackageTest extends TestCase
{
    private const VERSION = '2.0.0';

    private string $root;

    private string $base;

    private string $web;

    private string $secretKey;

    /** @var array<int, string> */
    private array $artisanCalls = [];

    private ?string $failingCommand = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('app/testing/release_'.uniqid());
        $this->base = $this->root.'/home/inventoros';
        $this->web = $this->root.'/home/public_html';

        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);

        config([
            'app.github_repo' => 'Inventoros/Inventoros',
            'update.signature.required' => true,
            'update.signature.public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'update.download_url_prefixes' => ['https://github.com/Inventoros/Inventoros/releases/download/'],
        ]);

        $this->makeInstalledApp();

        $this->app->instance(FileUpdateService::class, new FileUpdateService($this->base, $this->web));

        Artisan::shouldReceive('call')->andReturnUsing(function (string $command) {
            $this->artisanCalls[] = $command;
            if ($command === $this->failingCommand) {
                throw new \RuntimeException("{$command} boom");
            }

            return 0;
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing'));
        Mockery::close();

        parent::tearDown();
    }

    /**
     * A v1.0.8 cPanel install: the app in ~/inventoros, the web root in
     * ~/public_html, with user data that an update must never touch.
     */
    private function makeInstalledApp(): void
    {
        $files = [
            $this->base.'/app/OldService.php' => '<?php // removed in 2.0',
            $this->base.'/app/Kept.php' => '<?php // old kept',
            $this->base.'/vendor/autoload.php' => '<?php // old vendor',
            $this->base.'/vendor/old/package.php' => '<?php // old package',
            $this->base.'/config/app.php' => '<?php return ["old" => true];',
            // The default SQLite database lives inside an app-owned directory.
            $this->base.'/database/database.sqlite' => 'LIVE SQLITE DATA',
            $this->base.'/database/database.sqlite-wal' => 'LIVE WAL',
            $this->base.'/database/migrations/2020_01_01_000000_removed.php' => '<?php // gone in 2.0',
            $this->base.'/bootstrap/app.php' => '<?php // old bootstrap',
            $this->base.'/bootstrap/cache/packages.php' => '<?php return [];',
            $this->base.'/storage/app/uploads/invoice.pdf' => 'USER UPLOAD',
            $this->base.'/plugins/custom/plugin.json' => '{"name":"custom"}',
            $this->base.'/.env' => "APP_KEY=base64:keep-me\n",
            $this->base.'/VERSION' => '1.0.8',
            $this->base.'/artisan' => '<?php // old artisan',
            $this->web.'/index.php' => "<?php \$laravelPath = __DIR__ . '/../inventoros'; // 1.0.8",
            $this->web.'/.htaccess' => '# customised by the operator',
            $this->web.'/build/assets/app-OLD.js' => 'old bundle',
            $this->web.'/build/manifest.json' => '{"old":true}',
            $this->web.'/plugin-assets/custom/ui.js' => 'runtime plugin asset',
            $this->web.'/favicon.ico' => 'old icon',
        ];

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
        }
    }

    /**
     * Build a release archive the way the release workflow lays it out.
     *
     * @param  array<string, mixed>|null  $manifest  null = no release.json
     * @param  array<string, string>  $extra
     */
    private function makeRelease(?array $manifest = [], array $extra = []): string
    {
        $manifest = $manifest === null ? null : array_merge([
            'name' => 'inventoros',
            'version' => self::VERSION,
            'layout' => 'cpanel-v1',
            'min_php' => '8.4.1',
            'max_php' => null,
        ], $manifest);

        $entries = array_merge([
            'INSTALL.md' => '# install',
            'inventoros/app/Kept.php' => '<?php // new kept',
            'inventoros/app/NewService.php' => '<?php // added in 2.0',
            'inventoros/vendor/autoload.php' => '<?php // new vendor',
            'inventoros/config/app.php' => '<?php return ["new" => true];',
            'inventoros/database/migrations/2026_01_01_000000_added.php' => '<?php // new in 2.0',
            'inventoros/bootstrap/app.php' => '<?php // new bootstrap',
            'inventoros/bootstrap/cache/.gitkeep' => '',
            'inventoros/storage/app/.gitkeep' => '',
            'inventoros/storage/logs/.gitkeep' => '',
            'inventoros/plugins/hello-world/plugin.json' => '{"name":"hello-world"}',
            'inventoros/.env.example' => 'APP_KEY=',
            'inventoros/VERSION' => self::VERSION,
            'inventoros/artisan' => '<?php // new artisan',
            'inventoros/composer.json' => '{}',
            'public_html/index.php' => "<?php\n\$laravelPath = __DIR__ . '/../inventoros';\nrequire \$laravelPath . '/vendor/autoload.php';\n",
            'public_html/.htaccess' => '# release default',
            'public_html/build/manifest.json' => '{"new":true}',
            'public_html/build/assets/app-NEW.js' => 'new bundle',
            'public_html/favicon.ico' => 'new icon',
            'public_html/robots.txt' => 'User-agent: *',
        ], $extra);

        if ($manifest !== null) {
            $entries['release.json'] = json_encode($manifest);
        }

        $path = $this->root.'/'.uniqid('release_').'.zip';
        File::ensureDirectoryExists(dirname($path));
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    /**
     * Fake the GitHub API + release download for a v2.0.0 release whose
     * cPanel package is $zipPath, signed with the throwaway key.
     *
     * @param  array<int, array{name: string, path: string}>  $otherAssets
     */
    private function fakeGitHub(string $zipPath, array $otherAssets = []): void
    {
        $tag = 'v'.self::VERSION;
        $assets = [];
        $routes = [];

        foreach (array_merge($otherAssets, [['name' => GitHubReleaseService::packageName($tag), 'path' => $zipPath]]) as $asset) {
            $url = "https://github.com/Inventoros/Inventoros/releases/download/{$tag}/{$asset['name']}";
            $storage = 'https://release-assets.githubusercontent.com/asset/'.md5($asset['name']);
            $bytes = File::get($asset['path']);

            $assets[] = ['name' => $asset['name'], 'browser_download_url' => $url];
            $assets[] = ['name' => $asset['name'].'.sig', 'browser_download_url' => $url.'.sig'];

            $routes[$url] = Http::response('', 302, ['Location' => $storage]);
            $routes[$storage] = Http::response($bytes, 200);
            $routes[$url.'.sig'] = Http::response(base64_encode(sodium_crypto_sign_detached($bytes, $this->secretKey)), 200);
        }

        $routes['api.github.com/repos/Inventoros/Inventoros/releases/latest'] = Http::response([
            'tag_name' => $tag,
            'name' => "Release {$tag}",
            'assets' => $assets,
        ]);

        Http::fake($routes);
    }

    private function updater(?BackupService $backups = null): UpdateService
    {
        if ($backups === null) {
            $backups = Mockery::mock(BackupService::class);
            $backups->shouldReceive('createBackup')->andReturn($this->root.'/backup.zip');
            $backups->shouldReceive('lastDatabaseBackupMethod')->andReturn('php-dump');
        }

        return new UpdateService(
            new GitHubReleaseService,
            $backups,
            $this->app->make(FileUpdateService::class),
        );
    }

    public function test_a_cpanel_release_is_installed_into_the_app_and_web_root(): void
    {
        $this->fakeGitHub($this->makeRelease(), [
            // Sorts first and is a perfectly valid signed ZIP: must be ignored.
            ['name' => 'hello-world-plugin.zip', 'path' => $this->makeRelease(null)],
        ]);

        $result = $this->updater()->update();

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(self::VERSION, $result['new_version']);

        // App code, vendor and config come from inventoros/; files dropped
        // from the release are removed from app-owned directories.
        $this->assertSame('<?php // new kept', File::get($this->base.'/app/Kept.php'));
        $this->assertFileExists($this->base.'/app/NewService.php');
        $this->assertFileDoesNotExist($this->base.'/app/OldService.php');
        $this->assertSame('<?php // new vendor', File::get($this->base.'/vendor/autoload.php'));
        $this->assertFileDoesNotExist($this->base.'/vendor/old/package.php');
        $this->assertSame('<?php // new bootstrap', File::get($this->base.'/bootstrap/app.php'));
        $this->assertSame('<?php // new artisan', File::get($this->base.'/artisan'));
        $this->assertSame(self::VERSION, trim(File::get($this->base.'/VERSION')));

        // The database directory is replaced, but a SQLite database in it
        // is carried over.
        $this->assertFileExists($this->base.'/database/migrations/2026_01_01_000000_added.php');
        $this->assertFileDoesNotExist($this->base.'/database/migrations/2020_01_01_000000_removed.php');
        $this->assertSame('LIVE SQLITE DATA', File::get($this->base.'/database/database.sqlite'));
        $this->assertSame('LIVE WAL', File::get($this->base.'/database/database.sqlite-wal'));

        // User data and runtime state are untouched.
        // .env keeps its values and learns where the web root is, so CLI
        // commands on this split install use it too.
        $this->assertSame(
            "APP_KEY=base64:keep-me\nAPP_PUBLIC_PATH=\"../public_html\"\n",
            File::get($this->base.'/.env')
        );
        $this->assertSame('USER UPLOAD', File::get($this->base.'/storage/app/uploads/invoice.pdf'));
        $this->assertFileExists($this->base.'/plugins/custom/plugin.json');
        $this->assertFileDoesNotExist($this->base.'/plugins/hello-world/plugin.json');

        // The web root gets the new front controller and assets.
        $this->assertSame('{"new":true}', File::get($this->web.'/build/manifest.json'));
        $this->assertFileExists($this->web.'/build/assets/app-NEW.js');
        $this->assertFileDoesNotExist($this->web.'/build/assets/app-OLD.js');
        $this->assertSame('new icon', File::get($this->web.'/favicon.ico'));
        $this->assertFileExists($this->web.'/robots.txt');
        $index = File::get($this->web.'/index.php');
        $this->assertStringContainsString("\$laravelPath = __DIR__.'/../inventoros';", $index);
        $this->assertStringContainsString("require \$laravelPath . '/vendor/autoload.php';", $index);

        // ...but runtime plugin assets and the operator's .htaccess stay.
        $this->assertSame('runtime plugin asset', File::get($this->web.'/plugin-assets/custom/ui.js'));
        $this->assertSame('# customised by the operator', File::get($this->web.'/.htaccess'));

        // Caches are cleared before migrating and rebuilt after.
        $this->assertSame(['down', 'optimize:clear', 'migrate', 'optimize', 'queue:restart', 'up'], $this->artisanCalls);
    }

    public function test_index_php_points_at_the_real_app_directory_when_it_is_not_named_inventoros(): void
    {
        $custom = $this->root.'/home/apps/stock';
        File::moveDirectory($this->base, $custom);
        $this->base = $custom;
        $this->app->instance(FileUpdateService::class, new FileUpdateService($this->base, $this->web));

        $this->fakeGitHub($this->makeRelease());

        $result = $this->updater()->update();

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringContainsString("\$laravelPath = __DIR__.'/../apps/stock';", File::get($this->web.'/index.php'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>|null, 1: string}>
     */
    public static function invalidPackages(): array
    {
        return [
            'no manifest (e.g. a plugin zip)' => [null, 'release.json'],
            'manifest for another version' => [['version' => '1.9.0'], '1.9.0'],
            'unknown layout' => [['layout' => 'source'], 'layout'],
            'requires a newer PHP' => [['min_php' => '99.0.0'], '99.0.0'],
            'requires an older PHP' => [['max_php' => '7.4'], '7.4'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     */
    #[DataProvider('invalidPackages')]
    public function test_an_invalid_package_is_refused_before_maintenance_mode(?array $manifest, string $reason): void
    {
        $this->fakeGitHub($this->makeRelease($manifest));

        $result = $this->updater()->update();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString($reason, $result['message']);
        $this->assertNotContains('down', $this->artisanCalls);
        $this->assertNotContains('migrate', $this->artisanCalls);
        $this->assertSame('1.0.8', File::get($this->base.'/VERSION'));
        $this->assertFileExists($this->base.'/app/OldService.php');
    }

    public function test_a_release_without_the_cpanel_package_is_refused(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'tag_name' => 'v2.0.0',
                'zipball_url' => 'https://api.github.com/repos/Inventoros/Inventoros/zipball/v2.0.0',
                'assets' => [[
                    'name' => 'hello-world-plugin.zip',
                    'browser_download_url' => 'https://github.com/Inventoros/Inventoros/releases/download/v2.0.0/hello-world-plugin.zip',
                ]],
            ]),
        ]);

        $result = $this->updater()->update();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('inventoros-cpanel-2.0.0.zip', $result['message']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'hello-world-plugin.zip'));
    }

    public function test_the_update_is_refused_when_the_web_root_cannot_be_found(): void
    {
        // e.g. `php artisan update` on cPanel without APP_PUBLIC_PATH: the
        // default public path does not exist.
        $this->app->instance(FileUpdateService::class, new FileUpdateService($this->base, $this->base.'/public'));
        $this->fakeGitHub($this->makeRelease());

        $result = $this->updater()->update();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('APP_PUBLIC_PATH', $result['message']);
        $this->assertNotContains('down', $this->artisanCalls);
    }

    public function test_a_failed_migration_restores_app_files_web_root_and_database(): void
    {
        $state = new \stdClass;
        $state->restoredDatabase = false;
        $backups = new class($this->root.'/backups', $this->base, $this->web, $state) extends BackupService
        {
            public function __construct(string $backupPath, private string $fakeBase, private string $fakeWeb, private \stdClass $state)
            {
                parent::__construct($backupPath);
            }

            protected function basePath(): string
            {
                return $this->fakeBase;
            }

            protected function publicPath(): string
            {
                return $this->fakeWeb;
            }

            protected function backupDatabase(string $outputBase): ?array
            {
                File::put($outputBase.'.sql', '-- dump');

                return ['driver' => 'sqlite', 'method' => 'php-dump', 'path' => $outputBase.'.sql', 'file' => 'database.sql'];
            }

            public function restoreDatabase(string $extractPath): ?string
            {
                $this->state->restoredDatabase = File::exists($extractPath.'/database.sql');

                return 'php-dump';
            }
        };

        $this->failingCommand = 'migrate';

        $this->fakeGitHub($this->makeRelease());

        $result = $this->updater($backups)->update();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('restored', $result['message']);
        $this->assertTrue($state->restoredDatabase, 'The database dump must be restored.');

        $this->assertFileExists($this->base.'/app/OldService.php');
        $this->assertFileDoesNotExist($this->base.'/app/NewService.php');
        $this->assertSame('<?php // old vendor', File::get($this->base.'/vendor/autoload.php'));
        $this->assertSame('1.0.8', File::get($this->base.'/VERSION'));
        $this->assertSame('<?php // old artisan', File::get($this->base.'/artisan'));
        $this->assertFileExists($this->web.'/build/assets/app-OLD.js');
        $this->assertFileDoesNotExist($this->web.'/build/assets/app-NEW.js');
        $this->assertStringContainsString('1.0.8', File::get($this->web.'/index.php'));
        $this->assertSame('USER UPLOAD', File::get($this->base.'/storage/app/uploads/invoice.pdf'));
    }
}
