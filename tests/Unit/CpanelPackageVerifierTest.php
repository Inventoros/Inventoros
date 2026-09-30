<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

/**
 * deploy/cpanel/build-release.php --verify is the release workflow's gate
 * against shipping secrets, dev files or an incomplete package.
 */
final class CpanelPackageVerifierTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/testing/pkg_'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing'));
        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function goodEntries(): array
    {
        return [
            'release.json' => json_encode(['name' => 'inventoros', 'version' => '2.0.0', 'layout' => 'cpanel-v1', 'min_php' => '8.4.1', 'max_php' => '8.4']),
            'INSTALL.md' => '# install',
            'inventoros/artisan' => '<?php',
            'inventoros/VERSION' => '2.0.0',
            'inventoros/.env.example' => 'APP_KEY=',
            'inventoros/bootstrap/app.php' => '<?php',
            'inventoros/bootstrap/cache/.gitkeep' => '',
            'inventoros/storage/logs/.gitkeep' => '',
            'inventoros/vendor/autoload.php' => '<?php',
            'inventoros/vendor/laravel/framework/src/x.php' => '<?php',
            'inventoros/vendor/markbaker/complex/.github/workflows/main.yml' => 'ci',
            'public_html/index.php' => "<?php\nif (version_compare(PHP_VERSION, '8.4.1', '<')) { exit(1); }\n\$laravelPath = __DIR__ . '/../inventoros';\n",
            'public_html/.htaccess' => '',
            'public_html/build/manifest.json' => '{}',
        ];
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function verify(array $entries, string $name = 'inventoros-cpanel-2.0.0.zip'): Process
    {
        $path = $this->dir.'/'.$name;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }
        $zip->close();

        $process = new Process([
            (new PhpExecutableFinder)->find(),
            base_path('deploy/cpanel/build-release.php'),
            '--verify='.$path,
            '--version=2.0.0',
        ]);
        $process->run();

        return $process;
    }

    public function test_a_clean_package_passes(): void
    {
        $process = $this->verify($this->goodEntries());

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Verified inventoros-cpanel-2.0.0.zip', $process->getOutput());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function forbiddenPaths(): array
    {
        return [
            '.env' => ['inventoros/.env'],
            '.env.testing' => ['inventoros/.env.testing'],
            'tests' => ['inventoros/tests/Feature/ExampleTest.php'],
            'e2e' => ['inventoros/e2e/login.spec.ts'],
            'screenshots' => ['inventoros/screenshots/dashboard.png'],
            'docker' => ['inventoros/docker/entrypoint.sh'],
            'Dockerfile' => ['inventoros/Dockerfile'],
            'phpunit.xml' => ['inventoros/phpunit.xml'],
            'node_modules' => ['inventoros/plugins/hello-world/ui/node_modules/x.js'],
            'dev dependency' => ['inventoros/vendor/phpunit/phpunit/src/x.php'],
            'user upload' => ['inventoros/storage/app/uploads/invoice.pdf'],
            'log' => ['inventoros/storage/logs/laravel.log'],
            'compiled cache' => ['inventoros/bootstrap/cache/config.php'],
            'plugin assets' => ['public_html/plugin-assets/x/ui.js'],
            'vite hot file' => ['public_html/hot'],
        ];
    }

    #[DataProvider('forbiddenPaths')]
    public function test_a_forbidden_path_fails_the_package(string $path): void
    {
        $process = $this->verify($this->goodEntries() + [$path => 'x']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString($path, $process->getErrorOutput());
    }

    public function test_a_package_without_a_manifest_fails(): void
    {
        $entries = $this->goodEntries();
        unset($entries['release.json']);

        $process = $this->verify($entries);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('release.json: missing', $process->getErrorOutput());
    }

    public function test_a_manifest_for_another_version_fails(): void
    {
        $entries = $this->goodEntries();
        $entries['release.json'] = json_encode(['version' => '1.9.0', 'layout' => 'cpanel-v1', 'min_php' => '8.4.1']);

        $process = $this->verify($entries);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('expected 2.0.0', $process->getErrorOutput());
    }
}
