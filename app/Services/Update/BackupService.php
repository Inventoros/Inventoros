<?php

declare(strict_types=1);

namespace App\Services\Update;

use Exception;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Service for managing application backups.
 *
 * Creates, lists, and restores backups of the application files
 * and database for safe updates.
 *
 * Every archive carries a backup-manifest.json naming the database driver and
 * the method that captured it (mysqldump, pg_dump, sqlite-snapshot or
 * php-dump), so a restore replays it the matching way.
 */
class BackupService
{
    public const METHOD_MYSQLDUMP = 'mysqldump';

    public const METHOD_PG_DUMP = 'pg_dump';

    public const METHOD_SQLITE = 'sqlite-snapshot';

    public const METHOD_PHP = 'php-dump';

    /** Recorded when the operator allowed a backup without a database. */
    public const METHOD_NONE = 'none';

    public const MANIFEST = 'backup-manifest.json';

    /**
     * @var string Path to the backup storage directory
     */
    protected string $backupPath;

    protected ?string $lastDatabaseMethod = null;

    /**
     * Initialize the service and set up backup path.
     *
     * @param  string|null  $backupPath  Override the backup directory (mainly
     *                                   for testing); defaults to the app's
     *                                   storage/app/backups.
     * @param  string|null  $connection  Database connection to back up;
     *                                   defaults to the app's default connection.
     */
    public function __construct(?string $backupPath = null, protected ?string $connection = null)
    {
        $this->backupPath = $backupPath ?? storage_path('app/backups');
    }

    /**
     * The application base path that backups are taken relative to. Overridable
     * so the archive logic can be exercised against a fixture tree in tests.
     */
    protected function basePath(): string
    {
        return base_path();
    }

    /**
     * The top-level files and directories included in a backup.
     *
     * @return array<int, string>
     */
    protected function itemsToBackup(): array
    {
        return [
            '.env',
            'app',
            'bootstrap',
            'config',
            'database',
            'public',
            'resources',
            'routes',
            'storage',
        ];
    }

    /**
     * Create a backup of the current installation.
     *
     * Backs up application directories, configuration, and database.
     *
     * @return string Path to the created backup file
     *
     * @throws Exception If backup creation fails
     */
    public function createBackup(): string
    {
        $this->ensureBackupDirectoryExists();
        $this->lastDatabaseMethod = null;

        $timestamp = now()->format('Y-m-d_His');
        $backupFile = "{$this->backupPath}/backup_{$timestamp}.zip";

        // Capture the database first. If no method works, stop before an
        // archive exists that could be mistaken for a complete backup: the
        // updater must not replace files and run migrations without a way
        // back to the old data, unless the operator explicitly opted out.
        $database = $this->backupDatabase("{$this->backupPath}/database_{$timestamp}");

        if ($database === null) {
            if (! config('update.allow_missing_database_backup')) {
                throw new DatabaseBackupFailedException(
                    'Could not back up the database with any available method (see the log for details). '
                    .'Refusing to continue without a database backup. Install mysqldump/pg_dump or enable exec(), '
                    .'or set INVENTOROS_UPDATE_ALLOW_NO_DB_BACKUP=true to accept a files-only backup.'
                );
            }

            Log::warning('Creating a files-only backup: the database could not be backed up and INVENTOROS_UPDATE_ALLOW_NO_DB_BACKUP is set');
        }

        $this->lastDatabaseMethod = $database['method'] ?? self::METHOD_NONE;

        $zip = new ZipArchive;
        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Could not create backup file');
        }

        $basePath = $this->basePath();

        // Never recurse into the backup directory itself: it lives under
        // storage/, so backing "storage" up unguarded would embed every prior
        // backup (and the in-progress archive) into each new one, ballooning
        // disk use until backups fail.
        $excludePaths = [realpath($this->backupPath) ?: $this->backupPath];

        foreach ($this->itemsToBackup() as $item) {
            $path = "{$basePath}/{$item}";

            if (File::isFile($path)) {
                $zip->addFile($path, $item);
            } elseif (File::isDirectory($path)) {
                $this->addDirectoryToZip($zip, $path, $item, $excludePaths);
            }
        }

        // Fold the database dump INTO the archive, with a manifest saying how
        // it was made so a restore can replay it the matching way.
        if ($database !== null) {
            $zip->addFile($database['path'], $database['file']);
        }

        $zip->addFromString(self::MANIFEST, (string) json_encode([
            'format' => 1,
            'created_at' => now()->toIso8601String(),
            'database' => $database === null ? null : [
                'driver' => $database['driver'],
                'method' => $database['method'],
                'file' => $database['file'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $closed = $zip->close();

        // The dump is only read by addFile() at close(); remove the loose copy
        // now that it is safely inside the archive.
        if ($database !== null && File::exists($database['path'])) {
            File::delete($database['path']);
        }

        if (! $closed) {
            throw new Exception('Could not write the backup archive');
        }

        return $backupFile;
    }

    /**
     * How the database was captured by the last createBackup() call:
     * mysqldump, pg_dump, sqlite-snapshot, php-dump, or none (files-only
     * backup allowed by INVENTOROS_UPDATE_ALLOW_NO_DB_BACKUP).
     */
    public function lastDatabaseBackupMethod(): ?string
    {
        return $this->lastDatabaseMethod;
    }

    /**
     * List available backups.
     *
     * @return array<int, array{filename: string, path: string, size: int, created_at: int}> List of backup files sorted by creation time (newest first)
     */
    public function listBackups(): array
    {
        if (! File::exists($this->backupPath)) {
            return [];
        }

        $backups = [];
        $files = File::files($this->backupPath);

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.zip')) {
                $backups[] = [
                    'filename' => $file->getFilename(),
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'created_at' => $file->getMTime(),
                ];
            }
        }

        // Sort by creation time, newest first
        usort($backups, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $backups;
    }

    /**
     * Delete a backup file.
     *
     * @param  string  $filename  The backup filename to delete
     * @return bool True if deletion was successful, false if file not found
     */
    public function deleteBackup(string $filename): bool
    {
        $path = "{$this->backupPath}/{$filename}";

        if (! File::exists($path)) {
            return false;
        }

        return File::delete($path);
    }

    /**
     * Get backup path.
     *
     * @return string The backup storage directory path
     */
    public function getBackupPath(): string
    {
        return $this->backupPath;
    }

    /**
     * Ensure backup directory exists.
     */
    protected function ensureBackupDirectoryExists(): void
    {
        if (! File::exists($this->backupPath)) {
            File::makeDirectory($this->backupPath, config('limits.permissions.directory'), true);
        }
    }

    /**
     * Add directory recursively to zip.
     *
     * @param  ZipArchive  $zip  The zip archive to add files to
     * @param  string  $path  The filesystem path to the directory
     * @param  string  $zipPath  The path within the zip archive
     */
    protected function addDirectoryToZip(ZipArchive $zip, string $path, string $zipPath, array $excludePaths = []): void
    {
        $files = File::allFiles($path);

        foreach ($files as $file) {
            $filePath = $file->getRealPath();

            // A file that vanished mid-walk (transient cache/log files, broken
            // symlinks) resolves to false. Skip it rather than passing false to
            // the string-typed exclusion check and aborting the whole backup.
            if ($filePath === false) {
                continue;
            }

            if ($this->isExcluded($filePath, $excludePaths)) {
                continue;
            }

            $relativePath = $zipPath.'/'.$file->getRelativePathname();
            $zip->addFile($filePath, $relativePath);
        }
    }

    /**
     * Whether a file lives under any excluded directory.
     *
     * @param  array<int, string>  $excludePaths
     */
    protected function isExcluded(string $filePath, array $excludePaths): bool
    {
        $normalized = str_replace('\\', '/', $filePath);

        foreach ($excludePaths as $exclude) {
            $exclude = rtrim(str_replace('\\', '/', $exclude), '/');

            if ($exclude !== '' && str_starts_with($normalized, $exclude.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Back the database up by the best available method.
     *
     * - mysql/mariadb: mysqldump, then the pure-PHP dump
     * - pgsql:         pg_dump, then the pure-PHP dump
     * - sqlite:        a consistent file snapshot (VACUUM INTO), then the pure-PHP dump
     *
     * @param  string  $outputBase  Path prefix for the dump file (the extension is added per method)
     * @return array{driver: string, method: string, path: string, file: string}|null Null when every method failed
     */
    protected function backupDatabase(string $outputBase): ?array
    {
        $connection = DB::connection($this->connectionName());
        $driver = $this->normalizedDriver($connection);
        $config = $connection->getConfig();

        $attempts = [];

        if ($driver === 'sqlite') {
            $path = $outputBase.'.sqlite';
            $attempts[] = [self::METHOD_SQLITE, $path, 'database.sqlite',
                fn () => $this->sqliteSnapshot($connection, $path)];
        }

        if (in_array($driver, ['mysql', 'pgsql'], true) && $this->canExec()) {
            $path = $outputBase.'.sql';
            $attempts[] = $driver === 'mysql'
                ? [self::METHOD_MYSQLDUMP, $path, 'database.sql', fn () => $this->nativeDump('mysql', $config, $path)]
                : [self::METHOD_PG_DUMP, $path, 'database.sql', fn () => $this->nativeDump('pgsql', $config, $path)];
        }

        if (in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            $path = $outputBase.'.sql';
            $attempts[] = [self::METHOD_PHP, $path, 'database.sql', fn () => $this->phpDump($path)];
        }

        foreach ($attempts as [$method, $path, $file, $run]) {
            try {
                if (File::exists($path)) {
                    File::delete($path);
                }

                $run();

                clearstatcache(true, $path);
                if (! File::exists($path) || File::size($path) === 0) {
                    throw new RuntimeException('no output was produced');
                }

                Log::info('Database backup created', ['driver' => $driver, 'method' => $method]);

                return ['driver' => $driver, 'method' => $method, 'path' => $path, 'file' => $file];
            } catch (\Throwable $e) {
                Log::warning('Database backup method failed, trying the next one', [
                    'driver' => $driver,
                    'method' => $method,
                    'error' => $e->getMessage(),
                ]);

                if (File::exists($path)) {
                    File::delete($path);
                }
            }
        }

        Log::error('No database backup could be made', ['driver' => $driver]);

        return null;
    }

    /**
     * Restore the database from an extracted backup directory.
     *
     * @return string|null The method used, or null when the backup carries no database
     *
     * @throws RuntimeException When the backup has a database that could not be restored
     */
    public function restoreDatabase(string $extractPath): ?string
    {
        $entry = $this->databaseEntry($extractPath);
        if ($entry === null) {
            return null;
        }

        $connection = DB::connection($this->connectionName());
        $driver = $this->normalizedDriver($connection);

        if ($entry['driver'] !== $driver) {
            throw new RuntimeException(
                "The backup holds a {$entry['driver']} database but the app is configured for {$driver}; refusing to restore it"
            );
        }

        $path = $extractPath.'/'.basename($entry['file']);
        if (! File::exists($path)) {
            throw new RuntimeException("The backup manifest lists {$entry['file']} but it is missing from the archive");
        }

        match ($entry['method']) {
            self::METHOD_MYSQLDUMP, self::METHOD_PG_DUMP => $this->nativeRestore($driver, $connection->getConfig(), $path),
            self::METHOD_SQLITE => $this->sqliteRestore($connection, $path),
            self::METHOD_PHP => $this->phpRestore($path),
            default => throw new RuntimeException("Unknown database backup method '{$entry['method']}'"),
        };

        Log::info('Database restored from backup', ['driver' => $driver, 'method' => $entry['method']]);

        return $entry['method'];
    }

    /**
     * The database block of the backup manifest. Backups taken before the
     * manifest existed carry a bare database.sql, which only MySQL produced.
     *
     * @return array{driver: string, method: string, file: string}|null
     */
    protected function databaseEntry(string $extractPath): ?array
    {
        $manifestPath = $extractPath.'/'.self::MANIFEST;

        if (File::exists($manifestPath)) {
            $manifest = json_decode(File::get($manifestPath), true);
            if (! is_array($manifest) || ! array_key_exists('database', $manifest)) {
                throw new RuntimeException('The backup manifest is unreadable');
            }

            $database = $manifest['database'];
            if ($database === null) {
                return null;
            }

            if (! isset($database['driver'], $database['method'], $database['file'])) {
                throw new RuntimeException('The backup manifest database entry is incomplete');
            }

            return $database;
        }

        if (File::exists($extractPath.'/database.sql')) {
            return ['driver' => 'mysql', 'method' => self::METHOD_MYSQLDUMP, 'file' => 'database.sql'];
        }

        return null;
    }

    /**
     * Run mysqldump / pg_dump. Passwords go through the environment
     * (MYSQL_PWD / PGPASSWORD) so they never appear in the process list.
     *
     * @param  array<string, mixed>  $config
     */
    protected function nativeDump(string $driver, array $config, string $outputPath): void
    {
        if ($driver === 'mysql') {
            $command = sprintf(
                'mysqldump --user=%s --host=%s --port=%s --single-transaction --routines --triggers %s > %s',
                escapeshellarg((string) ($config['username'] ?? '')),
                escapeshellarg((string) ($config['host'] ?? '127.0.0.1')),
                escapeshellarg((string) ($config['port'] ?? '3306')),
                escapeshellarg((string) ($config['database'] ?? '')),
                escapeshellarg($outputPath)
            );
            $env = 'MYSQL_PWD';
        } else {
            $command = sprintf(
                'pg_dump --host=%s --port=%s --username=%s --dbname=%s --no-owner --no-privileges --clean --if-exists --file=%s',
                escapeshellarg((string) ($config['host'] ?? '127.0.0.1')),
                escapeshellarg((string) ($config['port'] ?? '5432')),
                escapeshellarg((string) ($config['username'] ?? '')),
                escapeshellarg((string) ($config['database'] ?? '')),
                escapeshellarg($outputPath)
            );
            $env = 'PGPASSWORD';
        }

        $code = $this->withEnv($env, (string) ($config['password'] ?? ''), fn () => $this->runCommand($command));

        if ($code !== 0) {
            throw new RuntimeException(strtok($command, ' ')." exited with code {$code}");
        }
    }

    /**
     * Load a mysqldump / pg_dump file with the matching client.
     *
     * @param  array<string, mixed>  $config
     */
    protected function nativeRestore(string $driver, array $config, string $sqlPath): void
    {
        if (! $this->canExec()) {
            throw new RuntimeException('This backup was made with a command-line dump tool, but exec() is disabled so it cannot be restored here');
        }

        if ($driver === 'mysql') {
            $command = sprintf(
                'mysql --user=%s --host=%s --port=%s %s < %s',
                escapeshellarg((string) ($config['username'] ?? '')),
                escapeshellarg((string) ($config['host'] ?? '127.0.0.1')),
                escapeshellarg((string) ($config['port'] ?? '3306')),
                escapeshellarg((string) ($config['database'] ?? '')),
                escapeshellarg($sqlPath)
            );
            $env = 'MYSQL_PWD';
        } else {
            $command = sprintf(
                'psql --quiet --no-psqlrc --set ON_ERROR_STOP=1 --single-transaction --host=%s --port=%s --username=%s --dbname=%s --file=%s',
                escapeshellarg((string) ($config['host'] ?? '127.0.0.1')),
                escapeshellarg((string) ($config['port'] ?? '5432')),
                escapeshellarg((string) ($config['username'] ?? '')),
                escapeshellarg((string) ($config['database'] ?? '')),
                escapeshellarg($sqlPath)
            );
            $env = 'PGPASSWORD';
        }

        $code = $this->withEnv($env, (string) ($config['password'] ?? ''), fn () => $this->runCommand($command));

        if ($code !== 0) {
            throw new RuntimeException(strtok($command, ' ')." exited with code {$code} while restoring the database");
        }
    }

    /**
     * A transactionally consistent copy of a SQLite database. VACUUM INTO
     * (SQLite 3.27+) is safe while the app is writing; a plain file copy is
     * the fallback for older SQLite builds.
     */
    protected function sqliteSnapshot(Connection $connection, string $outputPath): void
    {
        try {
            $connection->statement('VACUUM INTO '.$connection->getPdo()->quote($outputPath));

            return;
        } catch (\Throwable $e) {
            $source = (string) $connection->getConfig('database');
            if ($source === '' || $source === ':memory:' || ! File::exists($source)) {
                throw $e;
            }
        }

        if (! File::copy($source, $outputPath)) {
            throw new RuntimeException('Could not copy the SQLite database file');
        }
    }

    /**
     * Replace the live SQLite file with the snapshot. The copy lands next to
     * the target first and is then renamed over it, so a failed copy never
     * leaves a truncated database behind.
     */
    protected function sqliteRestore(Connection $connection, string $snapshotPath): void
    {
        $target = (string) $connection->getConfig('database');
        if ($target === '' || $target === ':memory:') {
            throw new RuntimeException('Cannot restore a SQLite snapshot into an in-memory database');
        }

        $staging = $target.'.restoring';
        if (! File::copy($snapshotPath, $staging)) {
            throw new RuntimeException('Could not stage the SQLite snapshot for restore');
        }

        DB::purge($connection->getName());

        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            if (File::exists($target.$suffix)) {
                File::delete($target.$suffix);
            }
        }

        if (! @rename($staging, $target)) {
            File::delete($staging);
            throw new RuntimeException('Could not replace the SQLite database file');
        }
    }

    /**
     * Pure-PHP dump of every table on the connection.
     */
    protected function phpDump(string $outputPath): void
    {
        $connection = DB::connection($this->connectionName());

        (new PhpDatabaseDump($connection))->dump($this->tableNames($connection), $outputPath, $this->dumpIsComplete());
    }

    /**
     * Whether tableNames() covers the whole schema. A complete dump also
     * drops, on restore, tables created after the backup (for example by
     * the migration that failed), so the schema comes back exactly.
     */
    protected function dumpIsComplete(): bool
    {
        return true;
    }

    protected function phpRestore(string $sqlPath): void
    {
        (new PhpDatabaseDump(DB::connection($this->connectionName())))->restore($sqlPath);
    }

    /**
     * Tables in the connection's current schema/database.
     *
     * @return list<string>
     */
    protected function tableNames(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();

        return array_values(array_column($schema->getTables($schema->getCurrentSchemaName()), 'name'));
    }

    /**
     * Whether shell commands can run at all (exec() present and not disabled).
     */
    protected function canExec(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('exec', $disabled, true);
    }

    protected function connectionName(): string
    {
        return $this->connection ?? (string) config('database.default');
    }

    private function normalizedDriver(Connection $connection): string
    {
        $driver = $connection->getDriverName();

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    /**
     * Run a shell command and return its exit code. Isolated so tests can
     * capture the command without executing it.
     */
    protected function runCommand(string $command): int
    {
        exec($command, $output, $returnCode);

        return $returnCode;
    }

    /**
     * Run $callback with an environment variable set (MYSQL_PWD, PGPASSWORD),
     * so a password reaches the child process without appearing on its
     * command line, where it would be visible in the host process list. The
     * previous value is restored afterwards.
     *
     * @param  \Closure(): int  $callback
     */
    protected function withEnv(string $name, string $value, \Closure $callback): int
    {
        $previous = getenv($name);
        putenv($name.'='.$value);

        try {
            return $callback();
        } finally {
            if ($previous === false) {
                putenv($name);
            } else {
                putenv($name.'='.$previous);
            }
        }
    }
}
