<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Produce a detached Ed25519 signature for a release archive.
 *
 * Reads the base64 secret key from INVENTOROS_UPDATE_SECRET_KEY (set this only
 * in the release-signing environment), signs the exact bytes of the given
 * file, and writes `<file><suffix>` (default `.sig`) containing the base64
 * signature. Upload that `.sig` alongside the archive in the GitHub release so
 * installs can verify it.
 */
final class SignUpdateArchiveCommand extends Command
{
    protected $signature = 'update:sign
        {file : Path to the archive to sign}
        {--suffix= : Signature file suffix (defaults to update.signature.asset_suffix)}';

    protected $description = 'Sign a release archive with the update signing secret key';

    public function handle(): int
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            $this->error('libsodium is required to sign releases but is not available.');

            return self::FAILURE;
        }

        $file = (string) $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("File not found or unreadable: {$file}");

            return self::FAILURE;
        }

        $secretB64 = (string) env('INVENTOROS_UPDATE_SECRET_KEY', '');
        if ($secretB64 === '') {
            $this->error('INVENTOROS_UPDATE_SECRET_KEY is not set. Run update:signing-keypair first.');

            return self::FAILURE;
        }

        $secretKey = base64_decode(trim($secretB64), true);
        if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->error('INVENTOROS_UPDATE_SECRET_KEY is not a valid Ed25519 secret key.');

            return self::FAILURE;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            $this->error("Failed to read file: {$file}");

            return self::FAILURE;
        }

        $signature = sodium_crypto_sign_detached($contents, $secretKey);
        sodium_memzero($secretKey);

        $suffix = (string) ($this->option('suffix') ?: config('update.signature.asset_suffix', '.sig'));
        $sigPath = $file.$suffix;

        if (file_put_contents($sigPath, base64_encode($signature)) === false) {
            $this->error("Failed to write signature file: {$sigPath}");

            return self::FAILURE;
        }

        $this->info("Signature written to {$sigPath}");

        return self::SUCCESS;
    }
}
