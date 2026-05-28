<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Verifies a detached Ed25519 signature over an update archive.
 *
 * The in-app updater downloads a release ZIP and replaces the running
 * application's files with its contents. Without integrity verification, a
 * compromised release asset (or a compromised GitHub account / mirror) is a
 * direct path to code execution. TLS protects the bytes in transit; this class
 * protects against the source itself serving a tampered archive.
 *
 * Format (deliberately dependency-free, verifiable with libsodium alone):
 *   - The release ships `<asset>.sig` alongside `<asset>.zip`.
 *   - The `.sig` file contains the base64-encoded raw 64-byte Ed25519
 *     signature of the exact archive bytes.
 *   - The matching base64 32-byte Ed25519 public key is committed to config
 *     (INVENTOROS_UPDATE_PUBLIC_KEY); the secret key lives only in the release
 *     signing environment (CI secret), never in the repo.
 *
 * Use `php artisan update:signing-keypair` to mint a key and
 * `php artisan update:sign <file>` to produce a `.sig`.
 */
final class ReleaseSignatureVerifier
{
    /**
     * Verify that $signatureB64 is a valid Ed25519 signature of the file at
     * $archivePath under $publicKeyB64. Throws on any failure — callers should
     * treat a thrown exception as "do not install this archive."
     *
     * @param  string  $archivePath  Absolute path to the downloaded archive.
     * @param  string  $signatureB64  Base64-encoded raw 64-byte detached signature.
     * @param  string  $publicKeyB64  Base64-encoded raw 32-byte Ed25519 public key.
     *
     * @throws RuntimeException When the key/signature are malformed, the file
     *                          is unreadable, or the signature does not match.
     */
    public static function verify(string $archivePath, string $signatureB64, string $publicKeyB64): void
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('libsodium is required to verify update signatures but is not available.');
        }

        $publicKey = self::decodeBase64($publicKeyB64, 'public key');
        $signature = self::decodeBase64($signatureB64, 'signature');

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Update signing public key has an invalid length.');
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('Update signature has an invalid length.');
        }

        if (! is_file($archivePath) || ! is_readable($archivePath)) {
            throw new RuntimeException("Cannot read archive for signature verification: {$archivePath}");
        }

        $contents = file_get_contents($archivePath);
        if ($contents === false) {
            throw new RuntimeException("Failed to read archive for signature verification: {$archivePath}");
        }

        if (! sodium_crypto_sign_verify_detached($signature, $contents, $publicKey)) {
            throw new RuntimeException(
                'Update archive failed signature verification. The download may be corrupt or tampered with; '
                .'aborting before any files are replaced.'
            );
        }
    }

    /**
     * Strictly decode a base64 blob, rejecting malformed input.
     *
     * @throws RuntimeException
     */
    private static function decodeBase64(string $value, string $label): string
    {
        $decoded = base64_decode(trim($value), true);

        if ($decoded === false || $decoded === '') {
            throw new RuntimeException("Update signing {$label} is not valid base64.");
        }

        return $decoded;
    }
}
