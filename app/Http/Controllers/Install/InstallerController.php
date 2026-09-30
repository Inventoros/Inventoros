<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Support\AppVersion;
use App\Support\PublicPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Controller for handling application installation.
 *
 * Manages the installation wizard including system requirements check,
 * database configuration, and admin account creation.
 */
class InstallerController extends Controller
{
    /**
     * Lowest supported PHP version. Keep in step with composer.json's "php" constraint.
     */
    public const PHP_MIN = '8.4.1';

    /**
     * First PHP version that is NOT supported, or null for no ceiling.
     * Must match the lowest upper bound in composer.lock (phpoffice/phpspreadsheet
     * 1.x capped PHP below 8.5; 5.x has no ceiling).
     */
    public const PHP_MAX_EXCLUSIVE = null;

    /**
     * Whether the given PHP version can run this release.
     */
    public static function phpVersionSupported(string $version): bool
    {
        if (version_compare($version, self::PHP_MIN, '<')) {
            return false;
        }

        return self::PHP_MAX_EXCLUSIVE === null || version_compare($version, self::PHP_MAX_EXCLUSIVE, '<');
    }

    /**
     * The PHP requirement as shown on the requirements step.
     */
    private static function phpRequirementLabel(): string
    {
        if (self::PHP_MAX_EXCLUSIVE === null) {
            return self::PHP_MIN.' or newer';
        }

        $ceiling = implode('.', array_slice(explode('.', self::PHP_MAX_EXCLUSIVE), 0, 2));

        return self::PHP_MIN.' or newer, below '.$ceiling;
    }

    /**
     * Show the installer welcome page.
     *
     * @return \Inertia\Response|\Illuminate\Http\RedirectResponse
     */
    public function index()
    {
        if ($this->isInstalled()) {
            return redirect('/login');
        }

        return Inertia::render('Install/Welcome');
    }

    /**
     * Check system requirements.
     *
     * @return \Inertia\Response|\Illuminate\Http\RedirectResponse
     */
    public function requirements()
    {
        if ($this->isInstalled()) {
            return redirect('/login');
        }

        $requirements = $this->checkRequirements();

        return Inertia::render('Install/Requirements', [
            'requirements' => $requirements,
            'allMet' => !in_array(false, array_column($requirements, 'met')),
        ]);
    }

    /**
     * Show database configuration form.
     *
     * @return \Inertia\Response|\Illuminate\Http\RedirectResponse
     */
    public function database()
    {
        if ($this->isInstalled()) {
            return redirect('/login');
        }

        // Adding PostgreSQL support alongside MySQL (issue #50). The welcome
        // page advertises both, the docker-compose ships a `postgres` profile,
        // but the wizard previously hardcoded MySQL everywhere.
        $driver = env('DB_CONNECTION', 'mysql');
        if (!in_array($driver, ['mysql', 'pgsql'], true)) {
            $driver = 'mysql';
        }

        return Inertia::render('Install/Database', [
            'currentConfig' => [
                'driver' => $driver,
                'host' => env('DB_HOST', 'localhost'),
                'port' => env('DB_PORT', $driver === 'pgsql' ? '5432' : '3306'),
                'database' => env('DB_DATABASE', ''),
                'username' => env('DB_USERNAME', ''),
            ],
        ]);
    }

    /**
     * Test database connection.
     *
     * @param Request $request The incoming HTTP request containing database credentials
     * @return \Illuminate\Http\JsonResponse
     */
    public function testDatabase(Request $request)
    {
        if ($this->isInstalled()) {
            return $this->answer(false, 'install.server.alreadyInstalled', 'Application is already installed.', 403);
        }

        $request->validate([
            'driver' => 'required|string|in:mysql,pgsql',
            'host' => 'required|string',
            'port' => 'required|integer',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'nullable|string',
        ]);

        try {
            $dsn = $this->buildDsn($request->driver, $request->host, (int) $request->port, $request->database);

            $connection = @new \PDO(
                $dsn,
                $request->username,
                $request->password
            );

            return $this->answer(true, 'install.server.connectionSuccessful', 'Database connection successful!');
        } catch (\Exception $e) {
            Log::warning('Database connection test failed', [
                'driver' => $request->driver,
                'host' => $request->host,
                'database' => $request->database,
                'error' => $e->getMessage(),
            ]);
            return $this->answer(false, 'install.server.connectionFailed', 'Database connection failed: '.$e->getMessage(), 422, $e->getMessage());
        }
    }

    /**
     * Build a PDO DSN string for the requested driver.
     *
     * Added for issue #50 — wizard previously hardcoded a MySQL DSN, which
     * made PostgreSQL setup impossible despite the docker-compose `postgres`
     * profile being available.
     */
    protected function buildDsn(string $driver, string $host, int $port, string $database): string
    {
        return match ($driver) {
            'pgsql' => "pgsql:host={$host};port={$port};dbname={$database}",
            default => "mysql:host={$host};port={$port};dbname={$database}",
        };
    }

    /**
     * Save database configuration and run migrations.
     *
     * With `reset_database` every table in the database is dropped first
     * (`migrate:fresh`). The wizard only offers that after a run failed on a
     * database that already has tables, typically an earlier attempt cut off
     * part-way through a migration (MySQL cannot roll back schema changes,
     * so the tables that migration created stay behind unrecorded and every
     * retry fails with "Table already exists").
     *
     * @param Request $request The incoming HTTP request containing database credentials
     * @return \Illuminate\Http\JsonResponse
     */
    public function installDatabase(Request $request)
    {
        if ($this->isInstalled()) {
            return $this->answer(false, 'install.server.alreadyInstalled', 'Application is already installed.', 403);
        }

        $request->validate([
            'driver' => 'required|string|in:mysql,pgsql',
            'host' => 'required|string',
            'port' => 'required|integer',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'nullable|string',
            'reset_database' => 'nullable|boolean',
        ]);

        $reset = $request->boolean('reset_database');

        // Running every migration takes longer than PHP's default 30 second
        // max_execution_time on many hosts; being cut off part-way leaves a
        // half-migrated database. Lift the limit (where the host allows it)
        // and finish even if the browser gives up waiting.
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        ignore_user_abort(true);

        try {
            // Update .env file with database config. The driver is written to
            // DB_CONNECTION inside updateEnvFile() (issue #50).
            $env = [
                'DB_CONNECTION' => $request->driver,
                'DB_HOST' => $request->host,
                'DB_PORT' => $request->port,
                'DB_DATABASE' => $request->database,
                'DB_USERNAME' => $request->username,
                'DB_PASSWORD' => $request->password ?? '',
                // Secure cookies only over https: a browser never sends a
                // Secure cookie back over plain http, which would lose the
                // session on every request (419 on every form).
                'SESSION_SECURE_COOKIE' => $this->servedOverHttps($request) ? 'true' : 'false',
            ];

            // On a split install (cPanel: ~/inventoros + ~/public_html) record
            // the web root this request is served from, so CLI commands
            // publish plugin assets and install updates into the same place.
            $publicPath = PublicPath::envValue(base_path(), public_path());
            if ($publicPath !== null) {
                $env['APP_PUBLIC_PATH'] = $publicPath;
            }

            $this->updateEnvFile($env);

            // Clear all caches, so the next request reads the new .env
            // rather than a cached config.
            Artisan::call('config:clear');
            Artisan::call('cache:clear');

            $this->prepareDatabase($request->driver, $request->only(['host', 'port', 'database', 'username', 'password']), $reset);

            return $this->answer(true, 'install.server.databaseInstalled', 'Database installed successfully!');
        } catch (\Throwable $e) {
            Log::error('Database installation failed', [
                'host' => $request->host,
                'database' => $request->database,
                'reset' => $reset,
                'error' => $e->getMessage(),
            ]);

            if (! $reset && $this->databaseHasTables()) {
                return $this->answer(
                    false,
                    'install.server.unfinishedInstall',
                    'The database already has tables, probably from an earlier installation that did not finish, and installing on top of them failed: '
                        .$e->getMessage(),
                    409,
                    $e->getMessage(),
                    ['can_reset' => true],
                );
            }

            return $this->answer(false, 'install.database.installFailed', 'Installation failed: '.$e->getMessage(), 500, $e->getMessage());
        }
    }

    /**
     * Point this request at the database the user chose and migrate it.
     *
     * Writing .env changes nothing for the running request: its config was
     * built at boot from the shipped .env (DB_CONNECTION=sqlite, no DB_HOST
     * or DB_DATABASE). Migrating without switching ran every migration into a
     * new database/database.sqlite, reported success and left the chosen
     * database empty. So the chosen connection is rebuilt from the submitted
     * settings, made the default, migrated explicitly, and checked afterwards.
     *
     * @param  array<string, mixed>  $input  host, port, database, username, password
     *
     * @throws \RuntimeException when the migrations did not reach the chosen database
     */
    protected function prepareDatabase(string $driver, array $input, bool $reset): void
    {
        config([
            "database.connections.{$driver}" => $this->connectionSettings($driver, $input),
            'database.default' => $driver,
        ]);
        DB::purge($driver);

        // A plain migrate resumes after the last migration that completed.
        Artisan::call($reset ? 'migrate:fresh' : 'migrate', ['--database' => $driver, '--force' => true]);

        $schema = Schema::connection($driver);
        if (! $schema->hasTable('migrations') || ! $schema->hasTable('system_settings')) {
            throw new \RuntimeException(sprintf(
                'The migrations finished but the tables are missing from the %s database "%s"; nothing was installed there.',
                $driver,
                (string) DB::connection($driver)->getDatabaseName(),
            ));
        }
    }

    /**
     * Connection settings for the chosen driver: the driver's configured
     * options (charset, SSL, search path, ...) with the location and
     * credentials the user submitted. Overridable for tests.
     *
     * @param  array<string, mixed>  $input  host, port, database, username, password
     * @return array<string, mixed>
     */
    protected function connectionSettings(string $driver, array $input): array
    {
        return array_merge((array) config("database.connections.{$driver}", []), [
            'driver' => $driver,
            // A DB_URL would take precedence over everything below.
            'url' => null,
            'host' => (string) $input['host'],
            'port' => (string) $input['port'],
            'database' => (string) $input['database'],
            'username' => (string) $input['username'],
            'password' => (string) ($input['password'] ?? ''),
        ]);
    }

    /**
     * A JSON answer for the wizard: the English message for API clients and
     * logs, and an i18n key (with the raw error as {error}) the wizard shows
     * in the user's language.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function answer(bool $success, string $key, string $message, int $status = 200, ?string $error = null, array $extra = []): \Illuminate\Http\JsonResponse
    {
        return response()->json(array_merge([
            'success' => $success,
            'message' => $message,
            'message_key' => $key,
            'error' => $error,
        ], $extra), $status);
    }

    /**
     * Whether the installation is served over https: this request is, or
     * APP_URL says so (TLS ended at a proxy that is not trusted yet).
     */
    protected function servedOverHttps(Request $request): bool
    {
        return $request->isSecure() || str_starts_with(strtolower((string) config('app.url')), 'https://');
    }

    /**
     * Whether the (newly configured) database already has any tables.
     */
    protected function databaseHasTables(): bool
    {
        try {
            return DB::connection()->getSchemaBuilder()->getTableListing() !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Show admin account creation form.
     *
     * @return \Inertia\Response|\Illuminate\Http\RedirectResponse
     */
    public function admin()
    {
        if ($this->isInstalled()) {
            return redirect('/login');
        }

        return Inertia::render('Install/Admin');
    }

    /**
     * Create admin account and organization.
     *
     * @param Request $request The incoming HTTP request containing admin account data
     * @return \Illuminate\Http\JsonResponse
     */
    public function createAdmin(Request $request)
    {
        if ($this->isInstalled()) {
            return $this->answer(false, 'install.server.alreadyInstalled', 'Application is already installed.', 403);
        }

        $validated = $request->validate([
            'organization_name' => 'required|string|max:255',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|string|email|max:255|unique:users,email',
            'admin_password' => 'required|string|min:8|confirmed',
        ]);

        try {
            DB::beginTransaction();

            // Create organization
            $organization = Organization::create([
                'name' => $validated['organization_name'],
                'is_active' => true,
            ]);

            // Create admin user
            User::create([
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
                'organization_id' => $organization->id,
                'role' => 'admin',
                'email_verified_at' => now(),
            ]);

            // Mark installation as complete
            SystemSetting::set('installed', true, 'boolean', 'Installation completed');
            SystemSetting::set('installed_at', now()->toDateTimeString(), 'string', 'Installation date');
            SystemSetting::set('app_version', AppVersion::current(), 'string', 'Application version');

            // Switch back to database sessions now that tables exist, and
            // leave the development defaults of .env.example: in debug mode
            // every error page shows a stack trace (paths, queries, settings)
            // to whoever hit it. APP_URL and SESSION_SECURE_COOKIE (set on
            // the database step) are left as they are.
            $this->updateEnvFile([
                'SESSION_DRIVER' => 'database',
                'CACHE_STORE' => 'database',
                'QUEUE_CONNECTION' => 'database',
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
            ]);

            DB::commit();

            return $this->answer(true, 'install.server.adminCreated', 'Admin account created successfully!');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin account creation failed during installation', [
                'organization_name' => $validated['organization_name'] ?? null,
                'admin_email' => $validated['admin_email'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return $this->answer(false, 'install.admin.createFailed', 'Failed to create admin account: '.$e->getMessage(), 500, $e->getMessage());
        }
    }

    /**
     * Show installation complete page.
     *
     * @return \Inertia\Response|\Illuminate\Http\RedirectResponse
     */
    public function complete()
    {
        if (!$this->isInstalled()) {
            return redirect()->route('install.index');
        }

        return Inertia::render('Install/Complete');
    }

    /**
     * Check if the application is already installed.
     *
     * @return bool
     */
    protected function isInstalled(): bool
    {
        try {
            return SystemSetting::get('installed', false) === true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check system requirements.
     *
     * @return array<string, array{name: string, required: string, current: string, met: bool}>
     */
    protected function checkRequirements(): array
    {
        return [
            [
                'name' => 'PHP Version',
                'required' => self::phpRequirementLabel(),
                'current' => PHP_VERSION,
                'met' => self::phpVersionSupported(PHP_VERSION),
            ],
            [
                'name' => 'PDO Extension',
                'required' => 'Enabled',
                'current' => extension_loaded('pdo') ? 'Enabled' : 'Disabled',
                'met' => extension_loaded('pdo'),
            ],
            [
                // At least one database driver must be available. The wizard
                // supports MySQL and PostgreSQL (issue #50).
                'name' => 'Database Driver (MySQL or PostgreSQL)',
                'required' => 'pdo_mysql or pdo_pgsql',
                'current' => $this->describeAvailableDbDrivers(),
                'met' => extension_loaded('pdo_mysql') || extension_loaded('pdo_pgsql'),
            ],
            [
                'name' => 'OpenSSL Extension',
                'required' => 'Enabled',
                'current' => extension_loaded('openssl') ? 'Enabled' : 'Disabled',
                'met' => extension_loaded('openssl'),
            ],
            [
                'name' => 'Mbstring Extension',
                'required' => 'Enabled',
                'current' => extension_loaded('mbstring') ? 'Enabled' : 'Disabled',
                'met' => extension_loaded('mbstring'),
            ],
            [
                'name' => 'Tokenizer Extension',
                'required' => 'Enabled',
                'current' => extension_loaded('tokenizer') ? 'Enabled' : 'Disabled',
                'met' => extension_loaded('tokenizer'),
            ],
            [
                'name' => 'JSON Extension',
                'required' => 'Enabled',
                'current' => extension_loaded('json') ? 'Enabled' : 'Disabled',
                'met' => extension_loaded('json'),
            ],
            [
                'name' => '.env File',
                'required' => 'Writable',
                'current' => is_writable(base_path('.env')) ? 'Writable' : 'Not Writable',
                'met' => is_writable(base_path('.env')),
            ],
            [
                'name' => 'Storage Directory',
                'required' => 'Writable',
                'current' => is_writable(storage_path()) ? 'Writable' : 'Not Writable',
                'met' => is_writable(storage_path()),
            ],
        ];
    }

    /**
     * Human-readable list of available PDO database drivers (issue #50).
     */
    protected function describeAvailableDbDrivers(): string
    {
        $available = array_filter([
            extension_loaded('pdo_mysql') ? 'pdo_mysql' : null,
            extension_loaded('pdo_pgsql') ? 'pdo_pgsql' : null,
        ]);

        return $available === [] ? 'None' : implode(', ', $available);
    }

    /**
     * Update .env file with new values.
     *
     * @param array<string, string|int|float|bool|null> $data The key-value pairs to update
     * @return void
     */
    protected function updateEnvFile(array $data): void
    {
        $envFile = $this->envFilePath();
        $envContent = file_get_contents($envFile);

        foreach ($data as $key => $value) {
            // Always quote + escape the value so passwords containing $, ",
            // backslash, #, or newlines write a valid env-file line that
            // phpdotenv will parse back to the original string. Previously
            // the raw $value was concatenated unquoted — a password like
            // p@ss$1word became a shell-style variable lookup at parse time,
            // pa"ss broke the line, and # truncated the value at a comment.
            // Values arrive straight from the request: the wizard posts the
            // port as a JSON number, which is an int here.
            $replacement = "{$key}=" . static::quoteEnvValue((string) $value);

            // Match the key at the start of a line (handles commented and uncommented)
            $pattern = "/^#?\s*{$key}=.*/m";

            $count = preg_match_all($pattern, $envContent);

            if ($count > 0) {
                // Replace only the first occurrence, remove subsequent ones
                $replaced = false;
                $envContent = preg_replace_callback($pattern, function($matches) use ($replacement, &$replaced) {
                    if (!$replaced) {
                        $replaced = true;
                        return $replacement;
                    }
                    return ''; // Remove duplicate entries
                }, $envContent);

                // Clean up empty lines created by removing duplicates
                $envContent = preg_replace("/\n\n\n+/", "\n\n", $envContent);
            } else {
                // Key doesn't exist, append it
                $envContent .= "\n{$replacement}";
            }
        }

        file_put_contents($envFile, $envContent);
    }

    /**
     * The .env file the installer writes. Overridable for tests.
     */
    protected function envFilePath(): string
    {
        return base_path('.env');
    }

    /**
     * Quote and escape a value for safe inclusion in a .env line.
     *
     * Wraps the value in double quotes and escapes the four characters
     * that change meaning inside a double-quoted phpdotenv string:
     * backslash, double-quote, dollar, plus literal newlines/carriage
     * returns which can't appear unescaped on a single .env line.
     */
    public static function quoteEnvValue(string $value): string
    {
        $escaped = strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            "\n" => '\\n',
            "\r" => '\\r',
        ]);

        return '"' . $escaped . '"';
    }
}
