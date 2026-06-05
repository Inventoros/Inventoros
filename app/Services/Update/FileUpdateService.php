<?php

declare(strict_types=1);

namespace App\Services\Update;

use App\Support\ReleaseSignatureVerifier;
use App\Support\SafeZipExtractor;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Service for handling file operations during updates.
 *
 * Downloads release archives, extracts them, and replaces
 * application files with new versions.
 */
class FileUpdateService
{
    /**
     * @var string Path to temporary storage directory for update files
     */
    protected string $tempPath;

    /**
     * Initialize the service and set up temp path.
     */
    public function __construct()
    {
        $this->tempPath = storage_path('app/temp');
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

        $zipPath = "{$this->tempPath}/update_".time().'.zip';

        // Disable redirect following so a 302 from an allowlisted host
        // cannot smuggle us off to an arbitrary URL.
        $response = Http::withOptions(['allow_redirects' => false])
            ->timeout(config('limits.timeouts.file_download'))
            ->get($url);

        if (! $response->successful()) {
            throw new Exception('Failed to download release');
        }

        File::put($zipPath, $response->body());

        return $zipPath;
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

        $response = Http::withOptions(['allow_redirects' => false])
            ->timeout(config('limits.timeouts.file_download'))
            ->get($signatureUrl);

        if (! $response->successful()) {
            throw new Exception(
                'Update signature could not be downloaded. The release must ship a detached '
                ."signature at {$suffix} alongside the archive."
            );
        }

        return trim($response->body());
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
     * Replace application files with new ones.
     *
     * Copies directories and key files from source to base path.
     *
     * @param  string  $sourcePath  Path to the extracted update files
     */
    public function replaceFiles(string $sourcePath): void
    {
        $basePath = base_path();

        $directoriesToUpdate = [
            'app',
            'bootstrap',
            'config',
            'database',
            'public',
            'resources',
            'routes',
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

        // Update composer files
        $composerFiles = ['composer.json', 'composer.lock'];
        foreach ($composerFiles as $file) {
            if (File::exists("{$sourcePath}/{$file}")) {
                File::copy("{$sourcePath}/{$file}", "{$basePath}/{$file}");
            }
        }

        // Update package files
        $packageFiles = ['package.json', 'package-lock.json', 'vite.config.js', 'tailwind.config.js'];
        foreach ($packageFiles as $file) {
            if (File::exists("{$sourcePath}/{$file}")) {
                File::copy("{$sourcePath}/{$file}", "{$basePath}/{$file}");
            }
        }
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
