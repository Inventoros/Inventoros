<?php

declare(strict_types=1);

namespace App\Services\Update;

use App\Support\PublicPath;
use App\Support\ReleaseSignatureVerifier;
use App\Support\SafeZipExtractor;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use ZipArchive;

/**
 * Service for handling file operations during updates.
 *
 * Downloads release archives, checks their release.json manifest, and
 * installs the cPanel package layout (`inventoros/` into the application
 * directory, `public_html/` into the web root). Also restores the files of
 * a pre-update backup.
 */
class FileUpdateService
{
    /** The only package layout this updater knows how to install. */
    public const LAYOUT = 'cpanel-v1';

    public const MANIFEST = 'release.json';

    /**
     * Top-level entries of the package's `inventoros/` folder that are never
     * copied over an install: they hold the operator's configuration,
     * uploads, logs, sessions and installed plugins. The version marker is
     * written separately, only after the migrations succeed.
     */
    private const APP_SKIP = ['storage', 'plugins', 'VERSION', '.env', 'node_modules', '.git'];

    /**
     * Web root entries that are runtime state (published plugin UI bundles,
     * the storage symlink, the Vite dev marker) and are never replaced.
     */
    private const WEB_SKIP = ['plugin-assets', 'plugins', 'storage', 'hot'];

    /**
     * Web root files the operator may have customised: only written when
     * they do not exist yet.
     */
    private const WEB_KEEP_IF_PRESENT = ['.htaccess', '.user.ini', 'web.config', 'robots.txt'];

    /** Matches the line of the cPanel front controller that locates the app. */
    private const LARAVEL_PATH_LINE = '/^([ \t]*)\$laravelPath\s*=\s*[^;]+;/m';

    /**
     * @var string Path to temporary storage directory for update files
     */
    protected string $tempPath;

    /**
     * @param  string|null  $basePath  The application directory to install into
     *                                 (defaults to base_path()).
     * @param  string|null  $publicPath  The web root to install into (defaults
     *                                   to public_path(), which honours
     *                                   APP_PUBLIC_PATH on split installs).
     */
    public function __construct(
        protected ?string $basePath = null,
        protected ?string $publicPath = null,
    ) {
        $this->tempPath = storage_path('app/temp');
    }

    /**
     * The application directory updates are installed into.
     */
    public function basePath(): string
    {
        return rtrim($this->basePath ?? base_path(), '/\\');
    }

    /**
     * The web root updates are installed into.
     */
    public function publicPath(): string
    {
        return rtrim($this->publicPath ?? public_path(), '/\\');
    }

    /**
     * Download release from URL.
     *
     * @param  string  $url  The URL to download the release from
     * @return string Path to the downloaded ZIP file
     *
     * @throws Exception If download fails
     */
    public function downloadRelease(string $url): string
    {
        $this->assertAllowedDownloadUrl($url);

        $this->ensureTempDirectoryExists();

        $zipPath = "{$this->tempPath}/update_".time().'_'.bin2hex(random_bytes(4)).'.zip';

        $this->fetch($url, $zipPath, (int) config('update.max_download_bytes', 200 * 1024 * 1024), 'release');

        return $zipPath;
    }

    /**
     * GET a URL, following redirects by hand, and stream the body to disk.
     *
     * GitHub answers every release asset with a 302 to a short-lived signed
     * storage URL, so redirects must be followed. Guzzle's automatic
     * following would accept any Location, so each hop is taken one at a time
     * instead: it must be https, its host must be in update.download_hosts
     * (or be the host of the allowlisted starting URL), and at most
     * update.max_redirects hops are allowed. The body is written in chunks
     * and the transfer is abandoned (and the partial file removed) as soon as
     * it passes $maxBytes, whatever Content-Length claimed.
     *
     * @throws Exception When a hop is refused, the chain is too long, the
     *                   server answers with an error, or the body is too big.
     */
    protected function fetch(string $url, string $destination, int $maxBytes, string $what): void
    {
        $maxRedirects = max(0, (int) config('update.max_redirects', 5));
        $startHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $current = $url;

        for ($hop = 0; ; $hop++) {
            $this->assertAllowedHop($current, $startHost);

            $response = Http::withOptions(['allow_redirects' => false, 'stream' => true])
                ->timeout(config('limits.timeouts.file_download'))
                ->get($current);

            if ($response->redirect()) {
                if ($hop >= $maxRedirects) {
                    throw new Exception("Too many redirects while downloading the {$what} (more than {$maxRedirects}).");
                }

                $location = trim((string) $response->header('Location'));
                if ($location === '') {
                    throw new Exception("The {$what} download redirected without a Location header.");
                }

                $current = $this->resolveLocation($current, $location);

                continue;
            }

            if (! $response->successful()) {
                throw new Exception("Failed to download the {$what} (HTTP {$response->status()}).");
            }

            $declared = $response->header('Content-Length');
            if ($declared !== '' && is_numeric($declared) && (int) $declared > $maxBytes) {
                throw new Exception("The {$what} download exceeds the size limit of {$maxBytes} bytes.");
            }

            $this->streamBody($response->toPsrResponse()->getBody(), $destination, $maxBytes, $what);

            return;
        }
    }

    /**
     * Copy a response body to disk in chunks, enforcing the size cap.
     *
     * @throws Exception
     */
    private function streamBody(StreamInterface $body, string $destination, int $maxBytes, string $what): void
    {
        $handle = @fopen($destination, 'wb');
        if ($handle === false) {
            throw new Exception("Could not write the {$what} download to {$destination}.");
        }

        $written = 0;
        $complete = false;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(1024 * 1024);
                if ($chunk === '') {
                    continue;
                }

                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new Exception("The {$what} download exceeds the size limit of {$maxBytes} bytes.");
                }

                if (fwrite($handle, $chunk) === false) {
                    throw new Exception("Could not write the {$what} download to disk.");
                }
            }

            $complete = true;
        } finally {
            fclose($handle);
            $body->close();

            if (! $complete) {
                @unlink($destination);
            }
        }
    }

    /**
     * Every hop of a download must be https and go to an allowlisted host.
     *
     * @throws Exception
     */
    protected function assertAllowedHop(string $url, string $startHost): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme !== 'https') {
            throw new Exception("Refusing to download over '{$scheme}': update downloads must use https.");
        }

        $allowed = array_map('strtolower', (array) config('update.download_hosts', []));
        $allowed[] = $startHost;

        if ($host === '' || ! in_array($host, $allowed, true)) {
            throw new Exception(
                "Refusing to follow a redirect to {$host}: the host is not in the update download allowlist "
                .'(INVENTOROS_UPDATE_HOSTS).'
            );
        }
    }

    /**
     * Resolve a Location header (absolute, scheme-relative or path-relative)
     * against the URL that returned it.
     */
    protected function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $authority = $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$authority}{$location}";
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return "{$scheme}://{$authority}{$directory}{$location}";
    }

    /**
     * Reject any download URL that doesn't start with an allowlisted prefix.
     *
     * The default allowlist points at this project's GitHub release endpoint.
     * Operators using a mirror or fork can override via the
     * INVENTOROS_UPDATE_PREFIXES env var (see config/update.php).
     *
     * @throws Exception If the URL does not match any allowed prefix.
     */
    protected function assertAllowedDownloadUrl(string $url): void
    {
        $prefixes = (array) config('update.download_url_prefixes', []);
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($url, $prefix)) {
                return;
            }
        }

        throw new Exception(
            'Update download URL is not in the allowed list. Add the prefix '
            .'to INVENTOROS_UPDATE_PREFIXES if you trust the source.'
        );
    }

    /**
     * Verify the downloaded archive against its detached Ed25519 signature
     * before it is extracted over the live application.
     *
     * Fails closed: with signature verification required (the default) but no
     * public key configured, this refuses the update rather than trusting an
     * unverified download. Operators who knowingly run unsigned builds set
     * INVENTOROS_UPDATE_SIGNATURE_REQUIRED=false.
     *
     * @param  string  $zipPath  Path to the downloaded archive on disk.
     * @param  string  $downloadUrl  The URL the archive came from; the detached
     *                               signature is fetched from this URL plus the
     *                               configured asset suffix (default `.sig`).
     *
     * @throws Exception When verification is required and fails for any reason.
     */
    public function verifyArchiveSignature(string $zipPath, string $downloadUrl): void
    {
        $required = (bool) config('update.signature.required', true);
        $publicKey = (string) config('update.signature.public_key', '');

        if (! $required) {
            if ($publicKey === '') {
                Log::warning('Update signature verification is disabled; installing an unverified archive.', [
                    'component' => 'update_service',
                ]);
            }

            return;
        }

        if ($publicKey === '') {
            throw new Exception(
                'Update signature verification is required but no signing public key is configured. '
                .'Set INVENTOROS_UPDATE_PUBLIC_KEY, or set INVENTOROS_UPDATE_SIGNATURE_REQUIRED=false '
                .'to install unsigned updates (not recommended).'
            );
        }

        $signatureB64 = $this->downloadSignature($downloadUrl);

        ReleaseSignatureVerifier::verify($zipPath, $signatureB64, $publicKey);
    }

    /**
     * Download the detached signature that sits next to the release asset.
     *
     * The signature URL is the download URL plus the configured suffix, so it
     * is served from the same allowlisted host and re-checked against the
     * allowlist before any bytes are fetched.
     *
     * @throws Exception When the signature cannot be retrieved.
     */
    protected function downloadSignature(string $downloadUrl): string
    {
        $suffix = (string) config('update.signature.asset_suffix', '.sig');
        $signatureUrl = $downloadUrl.$suffix;

        $this->assertAllowedDownloadUrl($signatureUrl);

        $this->ensureTempDirectoryExists();
        $path = "{$this->tempPath}/signature_".bin2hex(random_bytes(6)).'.sig';

        try {
            $this->fetch($signatureUrl, $path, 64 * 1024, 'signature');

            return trim((string) File::get($path));
        } catch (Exception $e) {
            throw new Exception(
                'Update signature could not be downloaded. The release must ship a detached '
                ."signature at {$suffix} alongside the archive. ({$e->getMessage()})",
                0,
                $e
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * Extract ZIP file to temporary directory.
     *
     * Handles GitHub's root folder structure by flattening if necessary.
     *
     * @param  string  $zipPath  Path to the ZIP file
     * @return string Path to the extracted directory
     *
     * @throws Exception If extraction fails
     */
    public function extractZip(string $zipPath): string
    {
        $extractPath = "{$this->tempPath}/extracted_".time();
        File::makeDirectory($extractPath, 0755, true, true);

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new Exception('Could not open downloaded ZIP file');
        }

        try {
            SafeZipExtractor::validate($zip, $extractPath, [
                'max_entries' => (int) config('update.max_entry_count', 50000),
                'max_bytes' => (int) config('update.max_extracted_bytes', 300 * 1024 * 1024),
            ]);

            // GitHub releases often have a root folder, we need to handle that
            $zip->extractTo($extractPath);
        } finally {
            $zip->close();
        }

        // Check if there's a single root directory
        $contents = File::directories($extractPath);
        if (count($contents) === 1 && count(File::files($extractPath)) === 0) {
            // Move contents up one level
            $rootDir = $contents[0];
            $tempExtractPath = $extractPath.'_final';
            File::moveDirectory($rootDir, $tempExtractPath);
            File::deleteDirectory($extractPath);
            File::moveDirectory($tempExtractPath, $extractPath);
        }

        return $extractPath;
    }

    /**
     * Check an extracted release package before anything on the live install
     * is touched.
     *
     * The package must carry a release.json manifest for the expected
     * version and the cPanel layout, this server's PHP must be inside the
     * manifest's supported range, the package must contain the files the
     * installer relies on, and the web root must be locatable.
     *
     * @param  string|null  $expectedVersion  The version being installed, or
     *                                        null to accept the manifest's own.
     * @return array{version: string, layout: string, min_php?: string|null, max_php?: string|null}
     *
     * @throws Exception When the package must not be installed.
     */
    public function validateRelease(string $extractPath, ?string $expectedVersion): array
    {
        $manifestPath = $extractPath.'/'.self::MANIFEST;

        if (! is_file($manifestPath)) {
            throw new Exception(
                'The downloaded archive is not an Inventoros release package: it has no '.self::MANIFEST.' manifest.'
            );
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            throw new Exception('The release package manifest ('.self::MANIFEST.') is not valid JSON.');
        }

        $version = ltrim(trim((string) ($manifest['version'] ?? '')), 'v');
        if ($version === '') {
            throw new Exception('The release package manifest does not name a version.');
        }

        $layout = (string) ($manifest['layout'] ?? '');
        if ($layout !== self::LAYOUT) {
            throw new Exception("Unsupported release package layout '{$layout}' (this updater installs '".self::LAYOUT."').");
        }

        if ($expectedVersion !== null && $version !== ltrim($expectedVersion, 'v')) {
            throw new Exception("The release package is for version {$version}, not ".ltrim($expectedVersion, 'v').'.');
        }

        $minPhp = trim((string) ($manifest['min_php'] ?? ''));
        if ($minPhp !== '' && version_compare($this->phpVersion(), $minPhp, '<')) {
            throw new Exception(
                "Inventoros {$version} requires PHP {$minPhp} or newer, but this server runs PHP {$this->phpVersion()}. "
                .'Upgrade PHP first (in cPanel: MultiPHP Manager), then run the update again.'
            );
        }

        $maxPhp = trim((string) ($manifest['max_php'] ?? ''));
        if ($maxPhp !== '' && ! $this->phpAtMost($maxPhp)) {
            throw new Exception(
                "Inventoros {$version} supports PHP up to {$maxPhp}, but this server runs PHP {$this->phpVersion()}."
            );
        }

        foreach (['inventoros/vendor/autoload.php', 'inventoros/bootstrap/app.php', 'inventoros/artisan', 'public_html/index.php'] as $required) {
            if (! is_file($extractPath.'/'.$required)) {
                throw new Exception("The release package is incomplete: {$required} is missing.");
            }
        }

        if (! preg_match(self::LARAVEL_PATH_LINE, (string) file_get_contents($extractPath.'/public_html/index.php'))) {
            throw new Exception('The release package front controller (public_html/index.php) does not define $laravelPath.');
        }

        $webRoot = $this->publicPath();
        if (! is_file($webRoot.'/index.php')) {
            throw new Exception(
                "Could not find the web root (no index.php in {$webRoot}). On a split install set APP_PUBLIC_PATH "
                .'in .env to your public_html directory, or run the update from Admin > Updates.'
            );
        }

        $manifest['version'] = $version;

        return $manifest;
    }

    /**
     * Install an extracted cPanel package over the live application.
     *
     * - `inventoros/` goes into the application directory. Code directories
     *   (app, config, database, resources, routes, vendor, ...) are replaced
     *   whole, so files dropped from the release disappear. storage/,
     *   plugins/, .env and bootstrap/cache are never touched.
     * - `public_html/` goes into the web root. build/ is replaced whole;
     *   index.php is rewritten to point at the real application directory;
     *   other files are overwritten; plugin-assets/ and the storage link are
     *   runtime state and left alone; .htaccess, .user.ini, web.config and
     *   robots.txt are only written when missing. Nothing else in the web
     *   root is deleted.
     *
     * @throws \RuntimeException When a file cannot be copied.
     */
    public function installRelease(string $extractPath): void
    {
        $source = $extractPath.'/inventoros';
        $base = $this->basePath();

        foreach ($this->entries($source) as $name) {
            if (in_array($name, self::APP_SKIP, true) || $this->isEnvFile($name)) {
                continue;
            }

            $from = "{$source}/{$name}";
            $to = "{$base}/{$name}";

            if ($name === 'bootstrap' && is_dir($from)) {
                // bootstrap/cache holds compiled caches; optimize:clear
                // empties it and optimize rebuilds it after the swap.
                foreach ($this->entries($from) as $child) {
                    if ($child !== 'cache') {
                        $this->installEntry("{$from}/{$child}", "{$to}/{$child}");
                    }
                }
                File::ensureDirectoryExists("{$to}/cache");

                continue;
            }

            $this->installEntry($from, $to);
        }

        $this->installWebRoot($extractPath.'/public_html', $this->publicPath(), restoring: false);

        $this->recordWebRoot();
    }

    /**
     * Installs from before 2.0 never recorded their web root, so CLI commands
     * on a split install wrote to <app>/public. Record it in .env (only when
     * missing) so the next `php artisan` run sees the same web root.
     */
    protected function recordWebRoot(): void
    {
        $value = PublicPath::envValue($this->basePath(), $this->publicPath());
        $envFile = $this->basePath().'/.env';

        if ($value === null || ! is_file($envFile) || ! is_writable($envFile)) {
            return;
        }

        $contents = (string) file_get_contents($envFile);
        if (preg_match('/^\s*APP_PUBLIC_PATH\s*=/m', $contents) === 1) {
            return;
        }

        $line = 'APP_PUBLIC_PATH="'.addcslashes($value, '"\\$').'"';
        $separator = ($contents === '' || str_ends_with($contents, "\n")) ? '' : "\n";

        file_put_contents($envFile, $contents.$separator.$line."\n");
    }

    /**
     * Record the installed version.
     */
    public function writeVersion(string $version): void
    {
        File::put($this->basePath().'/VERSION', $version);
    }

    /**
     * Restore application files from an extracted pre-update backup.
     *
     * @param  string  $sourcePath  Path to the extracted backup
     */
    public function replaceFiles(string $sourcePath): void
    {
        $basePath = $this->basePath();

        $directoriesToUpdate = [
            'app',
            'bootstrap',
            'config',
            'database',
            'lang',
            'resources',
            'routes',
            'vendor',
        ];

        foreach ($directoriesToUpdate as $dir) {
            $sourceDir = "{$sourcePath}/{$dir}";
            $destDir = "{$basePath}/{$dir}";

            if (File::exists($sourceDir)) {
                if (File::exists($destDir)) {
                    File::deleteDirectory($destDir);
                }

                if (! File::copyDirectory($sourceDir, $destDir)) {
                    throw new \RuntimeException("Failed to copy '{$dir}' during file replacement");
                }
            }
        }

        // Backups taken by this version hold the web root (wherever it
        // lives) under public/; older ones hold <app>/public.
        if (File::exists("{$sourcePath}/public")) {
            if ($this->backupHoldsWebRoot($sourcePath)) {
                $this->installWebRoot("{$sourcePath}/public", $this->publicPath(), restoring: true);
            } else {
                File::deleteDirectory("{$basePath}/public");
                if (! File::copyDirectory("{$sourcePath}/public", "{$basePath}/public")) {
                    throw new \RuntimeException("Failed to copy 'public' during file replacement");
                }
            }
        }

        foreach (['composer.json', 'composer.lock', 'artisan', 'VERSION'] as $file) {
            if (File::exists("{$sourcePath}/{$file}")) {
                File::copy("{$sourcePath}/{$file}", "{$basePath}/{$file}");
            }
        }
    }

    /**
     * Copy a web root tree into the live web root (see installRelease()).
     */
    protected function installWebRoot(string $source, string $webRoot, bool $restoring): void
    {
        File::ensureDirectoryExists($webRoot);

        foreach ($this->entries($source) as $name) {
            if (in_array($name, self::WEB_SKIP, true)) {
                continue;
            }

            $from = "{$source}/{$name}";
            $to = "{$webRoot}/{$name}";

            if (! $restoring && in_array($name, self::WEB_KEEP_IF_PRESENT, true) && file_exists($to)) {
                continue;
            }

            if ($name === 'index.php' && ! $restoring) {
                $this->putFile($to, $this->pointFrontControllerAtApp((string) file_get_contents($from)));

                continue;
            }

            if (is_dir($from)) {
                if ($name === 'build') {
                    $this->replaceDirectory($from, $to);
                } elseif (! File::copyDirectory($from, $to)) {
                    throw new \RuntimeException("Failed to copy '{$name}' into the web root");
                }

                continue;
            }

            $this->copyFile($from, $to);
        }
    }

    /**
     * Point the packaged front controller at this install's application
     * directory, which need not be ../inventoros.
     */
    protected function pointFrontControllerAtApp(string $contents): string
    {
        $expression = $this->laravelPathExpression();

        return (string) preg_replace_callback(
            self::LARAVEL_PATH_LINE,
            fn (array $m) => $m[1].'$laravelPath = '.$expression.';',
            $contents,
            1
        );
    }

    /**
     * A PHP expression for the application directory as seen from the web
     * root: relative to __DIR__ when possible, so the install survives a
     * home directory rename, otherwise absolute.
     */
    protected function laravelPathExpression(): string
    {
        $from = PublicPath::normalize(realpath($this->publicPath()) ?: $this->publicPath());
        $to = PublicPath::normalize(realpath($this->basePath()) ?: $this->basePath());

        $relative = PublicPath::relative($from, $to);
        if ($relative === null) {
            return var_export($to, true);
        }

        return $relative === '' ? '__DIR__' : "__DIR__.'/{$relative}'";
    }

    /**
     * Install one file or directory, replacing a directory whole.
     */
    protected function installEntry(string $from, string $to): void
    {
        if (is_dir($from)) {
            $this->replaceDirectory($from, $to);
        } else {
            $this->copyFile($from, $to);
        }
    }

    /**
     * Replace a directory with a copy of another.
     *
     * The copy is staged next to the destination first and swapped in with
     * renames, so the window in which the directory is missing or half
     * written is as short as the filesystem allows.
     *
     * @throws \RuntimeException
     */
    protected function replaceDirectory(string $from, string $to): void
    {
        $suffix = bin2hex(random_bytes(4));
        $staged = "{$to}.incoming-{$suffix}";
        $outgoing = "{$to}.outgoing-{$suffix}";

        if (! File::copyDirectory($from, $staged)) {
            File::deleteDirectory($staged);
            throw new \RuntimeException("Failed to copy '".basename($to)."' during the update");
        }

        $hadOld = is_dir($to);
        if ($hadOld && ! @rename($to, $outgoing)) {
            // Cannot move the old tree aside (e.g. a locked file on Windows):
            // fall back to replacing it in place.
            File::deleteDirectory($staged);
            File::deleteDirectory($to);
            if (! File::copyDirectory($from, $to)) {
                throw new \RuntimeException("Failed to copy '".basename($to)."' during the update");
            }

            return;
        }

        if (! @rename($staged, $to)) {
            if ($hadOld) {
                @rename($outgoing, $to);
            }
            File::deleteDirectory($staged);
            throw new \RuntimeException("Failed to move the new '".basename($to)."' into place");
        }

        if ($hadOld) {
            File::deleteDirectory($outgoing);
        }
    }

    /**
     * @throws \RuntimeException
     */
    protected function copyFile(string $from, string $to): void
    {
        File::ensureDirectoryExists(dirname($to));

        if (! File::copy($from, $to)) {
            throw new \RuntimeException("Failed to copy '".basename($to)."' during the update");
        }
    }

    /**
     * @throws \RuntimeException
     */
    protected function putFile(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));

        if (File::put($path, $contents) === false) {
            throw new \RuntimeException("Failed to write '".basename($path)."' during the update");
        }
    }

    /**
     * Names in a directory (including dotfiles), sorted.
     *
     * @return array<int, string>
     */
    protected function entries(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $names = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($names);

        return $names;
    }

    private function isEnvFile(string $name): bool
    {
        return $name !== '.env.example' && preg_match('/^\.env(\..+)?$/', $name) === 1;
    }

    /**
     * Whether a backup's public/ folder is a copy of the web root (backups
     * made by this version record that in their manifest).
     */
    protected function backupHoldsWebRoot(string $sourcePath): bool
    {
        $manifest = $sourcePath.'/'.BackupService::MANIFEST;
        if (! is_file($manifest)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($manifest), true);

        return is_array($data) && ($data['public'] ?? null) === BackupService::PUBLIC_WEB_ROOT;
    }

    protected function phpVersion(): string
    {
        return PHP_VERSION;
    }

    /**
     * Whether this PHP is within a maximum. "8.5" means any 8.5.x;
     * "8.5.3" is an exact ceiling.
     */
    protected function phpAtMost(string $max): bool
    {
        if (preg_match('/^\d+\.\d+$/', $max) === 1) {
            return version_compare(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, $max, '<=');
        }

        return version_compare($this->phpVersion(), $max, '<=');
    }

    /**
     * Cleanup temporary files after update.
     *
     * @param  string  $zipPath  Path to the downloaded ZIP file
     * @param  string  $extractPath  Path to the extracted directory
     */
    public function cleanup(string $zipPath, string $extractPath): void
    {
        if (File::exists($zipPath)) {
            File::delete($zipPath);
        }

        if (File::exists($extractPath)) {
            File::deleteDirectory($extractPath);
        }
    }

    /**
     * Get temp path.
     *
     * @return string The temporary storage path
     */
    public function getTempPath(): string
    {
        return $this->tempPath;
    }

    /**
     * Ensure temp directory exists.
     */
    protected function ensureTempDirectoryExists(): void
    {
        if (! File::exists($this->tempPath)) {
            File::makeDirectory($this->tempPath, config('limits.permissions.directory'), true);
        }
    }
}
