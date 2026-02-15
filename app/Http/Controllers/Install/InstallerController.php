<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Models\Auth\Organization;
use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

        return Inertia::render('Install/Database', [
            'currentConfig' => [
                'host' => env('DB_HOST', 'localhost'),
                'port' => env('DB_PORT', '3306'),
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
        $request->validate([
            'host' => 'required|string',
            'port' => 'required|integer',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'nullable|string',
        ]);

        try {
            $connection = @new \PDO(
                "mysql:host={$request->host};port={$request->port};dbname={$request->database}",
                $request->username,
                $request->password
            );

            return response()->json([
                'success' => true,
                'message' => 'Database connection successful!',
            ]);
        } catch (\Exception $e) {
            Log::warning('Database connection test failed', [
                'host' => $request->host,
                'database' => $request->database,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Database connection failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Save database configuration and run migrations.
     *
     * @param Request $request The incoming HTTP request containing database credentials
     * @return \Illuminate\Http\JsonResponse
     */
    public function installDatabase(Request $request)
    {
        $request->validate([
            'host' => 'required|string',
            'port' => 'required|integer',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'nullable|string',
        ]);

        try {
            // Update .env file with database config
            $this->updateEnvFile([
                'DB_HOST' => $request->host,
                'DB_PORT' => $request->port,
                'DB_DATABASE' => $request->database,
                'DB_USERNAME' => $request->username,
                'DB_PASSWORD' => $request->password ?? '',
            ]);

            // Clear all caches
            Artisan::call('config:clear');
            Artisan::call('cache:clear');

            // Reconnect to database with new config
            DB::purge('mysql');
            DB::reconnect('mysql');

            // Run migrations
            Artisan::call('migrate', ['--force' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Database installed successfully!',
            ]);
        } catch (\Exception $e) {
            Log::error('Database installation failed', [
                'host' => $request->host,
                'database' => $request->database,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Installation failed: ' . $e->getMessage(),
            ], 500);
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
            SystemSetting::set('app_version', config('app.version', '0.1.0'), 'string', 'Application version');

            // Switch back to database sessions now that tables exist
            $this->updateEnvFile([
                'SESSION_DRIVER' => 'database',
                'CACHE_STORE' => 'database',
                'QUEUE_CONNECTION' => 'database',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Admin account created successfully!',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin account creation failed during installation', [
                'organization_name' => $validated['organization_name'] ?? null,
                'admin_email' => $validated['admin_email'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create admin account: ' . $e->getMessage(),
            ], 500);
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
                'required' => '8.2.0',
                'current' => PHP_VERSION,
                'met' => version_compare(PHP_VERSION, '8.2.0', '>='),
            ],
            [
                'name' => 'PDO Extension',
                'required' => 'Enabled',
                'current' => extension_loaded('pdo') ? 'Enabled' : 'Disabled',
                'met' => extension_loaded('pdo'),
            ],
            [
                'name' => 'MySQL Extension',
                'required' => 'Enabled',
                'current' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Disabled',
                'met' => extension_loaded('pdo_mysql'),
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
     * Update .env file with new values.
     *
     * @param array<string, string> $data The key-value pairs to update
     * @return void
     */
    protected function updateEnvFile(array $data): void
    {
        $envFile = base_path('.env');
        $envContent = file_get_contents($envFile);

        // Also update DB_CONNECTION to mysql
        $data['DB_CONNECTION'] = 'mysql';

        foreach ($data as $key => $value) {
            // Escape special regex characters in the value
            $escapedValue = preg_quote($value, '/');

            // Match the key at the start of a line (handles commented and uncommented)
            $pattern = "/^#?\s*{$key}=.*/m";
            $replacement = "{$key}={$value}";

            // Count how many times this key appears
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
}
