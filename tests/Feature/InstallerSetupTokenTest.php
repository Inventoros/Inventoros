<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Install\InstallerController;
use App\Support\InstallerSetupToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Concerns\UnlocksInstaller;
use Tests\TestCase;

/**
 * Until Inventoros is installed, the web installer creates the first
 * organization and administrator. Only someone who can read the server's
 * configuration or files may run it: every step except the landing page
 * answers only a session that entered the one-time setup token.
 */
final class InstallerSetupTokenTest extends TestCase
{
    use RefreshDatabase;
    use UnlocksInstaller;

    private string $directory;

    private string $tokenFile;

    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/setup-token-'.bin2hex(random_bytes(6)));
        $this->tokenFile = $this->directory.'/private/setup-token';
        $this->envFile = $this->directory.'/installer.env';
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->envFile, "APP_NAME=Inventoros\nDB_CONNECTION=sqlite\n");

        $this->app->instance(InstallerSetupToken::class, new InstallerSetupToken($this->tokenFile));

        $envFile = $this->envFile;
        $this->app->bind(InstallerController::class, fn () => new class($envFile) extends InstallerController
        {
            public function __construct(private string $testEnvFile) {}

            protected function envFilePath(): string
            {
                return $this->testEnvFile;
            }

            // The suite's database is already migrated.
            protected function prepareDatabase(string $driver, array $input, bool $reset): void {}
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function database(array $overrides = []): array
    {
        return $overrides + [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'inventoros',
            'username' => 'inventoros',
            'password' => 'secret',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function admin(): array
    {
        return [
            'organization_name' => 'Acme',
            'admin_name' => 'Ada',
            'admin_email' => 'ada@example.com',
            'admin_password' => 'StrongPass123',
            'admin_password_confirmation' => 'StrongPass123',
        ];
    }

    private function useTheTokenFile(): void
    {
        config(['app.setup_token' => null]);
    }

    public function test_the_landing_page_stays_open_and_reports_the_lock(): void
    {
        $response = $this->get('/install')->assertOk();

        $this->assertFalse($response->viewData('page')['props']['unlocked']);
        $this->assertTrue($response->viewData('page')['props']['tokenAvailable']);
    }

    public function test_the_landing_page_says_when_no_token_can_be_created(): void
    {
        // No configured token, and the token file cannot be written: its
        // directory would have to be created inside a regular file.
        $this->useTheTokenFile();
        $this->app->instance(InstallerSetupToken::class, new InstallerSetupToken($this->envFile.'/private/setup-token'));
        Log::spy();

        $props = $this->get('/install')->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['unlocked']);
        $this->assertFalse($props['tokenAvailable'], 'The page must tell the operator to set INVENTOROS_SETUP_TOKEN or fix permissions.');
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message) => str_contains($message, 'INVENTOROS_SETUP_TOKEN'),
        )->atLeast()->once();
        $this->postJson('/install/unlock', ['token' => ''])->assertStatus(422);
        $this->postJson('/install/unlock', ['token' => 'anything'])->assertForbidden();
    }

    public function test_generating_the_token_never_changes_the_process_umask(): void
    {
        // The umask is process-wide: under threaded PHP (FrankenPHP) changing
        // it, even briefly, affects files other requests create meanwhile.
        $source = (string) file_get_contents(app_path('Support/InstallerSetupToken.php'));
        $this->assertStringNotContainsString('umask(', $source);

        $this->useTheTokenFile();
        $before = umask();
        $this->get('/install')->assertOk();
        $this->assertFileExists($this->tokenFile);
        $this->assertSame($before, umask());
    }

    public function test_every_step_refuses_a_session_without_the_token(): void
    {
        $this->get('/install/requirements')->assertRedirect(route('install.index'));
        $this->get('/install/database')->assertRedirect(route('install.index'));
        $this->get('/install/admin')->assertRedirect(route('install.index'));

        foreach ([
            '/install/database/test' => $this->database(),
            '/install/database/install' => $this->database(),
            '/install/admin' => $this->admin(),
        ] as $uri => $payload) {
            $this->postJson($uri, $payload)
                ->assertForbidden()
                ->assertJson(['success' => false, 'message_key' => 'install.server.locked']);
        }

        $this->assertSame("APP_NAME=Inventoros\nDB_CONNECTION=sqlite\n", file_get_contents($this->envFile));
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
        $this->assertDatabaseMissing('organizations', ['name' => 'Acme']);
    }

    public function test_a_wrong_token_is_refused(): void
    {
        config(['app.setup_token' => 'the-right-setup-token']);

        $this->postJson('/install/unlock', ['token' => 'a-wrong-setup-token'])
            ->assertForbidden()
            ->assertJson(['success' => false, 'message_key' => 'install.server.invalidToken']);
        $this->postJson('/install/unlock', [])->assertStatus(422);

        $this->get('/install/database')->assertRedirect(route('install.index'));
        $this->postJson('/install/admin', $this->admin())->assertForbidden();
    }

    public function test_the_configured_token_unlocks_the_installer(): void
    {
        $this->unlockInstaller('configured-setup-token-value');

        $this->get('/install')->assertOk();
        $this->assertTrue($this->get('/install')->viewData('page')['props']['unlocked']);
        $this->get('/install/requirements')->assertOk();
        $this->get('/install/database')->assertOk();
        $this->get('/install/admin')->assertOk();
        $this->assertFileDoesNotExist($this->tokenFile, 'A configured token needs no file.');
    }

    public function test_the_token_file_is_generated_on_the_first_visit_and_unlocks_the_installer(): void
    {
        $this->useTheTokenFile();
        Log::spy();

        $this->get('/install')->assertOk();

        $this->assertFileExists($this->tokenFile);
        $token = trim((string) file_get_contents($this->tokenFile));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{32,}\z/', $token, 'At least 128 bits, URL-safe.');
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($this->tokenFile)), -4));
            // The private disk must stay usable by CLI and worker processes
            // running as another user; only the token file itself is private.
            $this->assertSame(sprintf('%o', 0755 & ~umask()), sprintf('%o', fileperms(dirname($this->tokenFile)) & 0777));
        }
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => str_contains($message, $this->tokenFile)
                && ! str_contains($message, $token)
                && ! str_contains(json_encode($context), $token),
        )->once();

        // Later visits keep the same token.
        $this->get('/install')->assertOk();
        $this->assertSame($token, trim((string) file_get_contents($this->tokenFile)));

        $this->postJson('/install/unlock', ['token' => 'not-the-file-token'])->assertForbidden();
        $this->postJson('/install/unlock', ['token' => $token])->assertOk()->assertJson(['success' => true]);
        $this->get('/install/database')->assertOk();
    }

    public function test_the_configured_token_takes_priority_over_the_file(): void
    {
        File::ensureDirectoryExists(dirname($this->tokenFile));
        file_put_contents($this->tokenFile, "token-from-an-old-file\n");
        config(['app.setup_token' => 'configured-setup-token-value']);

        $this->postJson('/install/unlock', ['token' => 'token-from-an-old-file'])->assertForbidden();
        $this->postJson('/install/unlock', ['token' => 'configured-setup-token-value'])->assertOk();
    }

    public function test_an_unlocked_session_locks_again_when_the_token_changes(): void
    {
        $this->unlockInstaller('first-setup-token-value');
        $this->get('/install/database')->assertOk();

        config(['app.setup_token' => 'second-setup-token-value']);

        $this->get('/install/database')->assertRedirect(route('install.index'));
    }

    public function test_unlocking_is_throttled(): void
    {
        config(['app.setup_token' => 'the-right-setup-token']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/install/unlock', ['token' => 'guess-'.$i])->assertForbidden();
        }

        $this->postJson('/install/unlock', ['token' => 'the-right-setup-token'])->assertStatus(429);
    }

    public function test_the_installer_steps_are_throttled(): void
    {
        $this->unlockInstaller();

        $statuses = [];
        for ($i = 0; $i < 25; $i++) {
            $statuses[] = $this->postJson('/install/database/test', $this->database(['host' => 'bad;host']))->status();
        }

        $this->assertContains(429, $statuses);
    }

    public function test_the_full_installation_completes_and_removes_the_token_file(): void
    {
        $this->useTheTokenFile();
        $this->get('/install')->assertOk();
        $token = trim((string) file_get_contents($this->tokenFile));

        $this->postJson('/install/unlock', ['token' => $token])->assertOk();
        $this->get('/install/requirements')->assertOk();
        $this->get('/install/database')->assertOk();
        $this->postJson('/install/database/install', $this->database())->assertOk()->assertJson(['success' => true]);
        $this->get('/install/admin')->assertOk();
        $this->postJson('/install/admin', $this->admin())->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
        $this->assertFileDoesNotExist($this->tokenFile);
        $this->assertNull(session(InstallerSetupToken::SESSION_KEY));
        $this->get('/install/complete')->assertOk();
        $this->get('/install')->assertRedirect('/login');
        $this->postJson('/install/unlock', ['token' => $token])->assertForbidden()
            ->assertJson(['message_key' => 'install.server.alreadyInstalled']);
        $this->postJson('/install/admin', $this->admin())->assertForbidden()
            ->assertJson(['message_key' => 'install.server.alreadyInstalled']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dsnMetacharacters(): array
    {
        return [
            'semicolon in host' => ['host', 'db;port=1'],
            'equals in host' => ['host', 'host=db'],
            'space in host' => ['host', 'db example'],
            'NUL in host' => ['host', "db\0x"],
            'semicolon in database' => ['database', 'inventoros;sslmode=disable'],
            'equals in database' => ['database', 'dbname=other'],
            'tab in database' => ['database', "inv\toros"],
            'NUL in database' => ['database', "inv\0oros"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dsnMetacharacters')]
    public function test_dsn_metacharacters_are_rejected(string $field, string $value): void
    {
        $this->unlockInstaller();

        foreach (['/install/database/test', '/install/database/install'] as $uri) {
            $this->postJson($uri, $this->database([$field => $value]))
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame("APP_NAME=Inventoros\nDB_CONNECTION=sqlite\n", file_get_contents($this->envFile));
    }

    public function test_a_failed_connection_test_does_not_reveal_the_driver_error(): void
    {
        $this->unlockInstaller();
        Log::spy();

        $response = $this->postJson('/install/database/test', $this->database(['port' => 1]))
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message_key' => 'install.server.connectionFailed']);

        $this->assertNull($response->json('error'));
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
        $this->assertSame('Database connection failed. Check the host, port, database name, user and password.', $response->json('message'));
        // The cause is logged for the operator.
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => $message === 'Database connection test failed' && ($context['error'] ?? '') !== '',
        )->once();
    }
}
