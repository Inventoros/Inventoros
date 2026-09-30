<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

/**
 * What the marketplace signs for a published plugin version.
 *
 * Signing only the ZIP bytes proved that a package came from the marketplace
 * but not WHICH plugin or version it is, so an older genuinely-signed
 * package could be served in place of a newer one (a downgrade) and the
 * version the app compared against came from the unsigned catalog JSON.
 * The signature therefore covers a statement binding the three together:
 *
 *     inventoros-marketplace-package-v1\n<slug>\n<version>\n<sha256 hex>\n
 *
 * The site (inventoros.com, App\Services\Marketplace\MarketplaceSigner)
 * builds exactly the same bytes; keep the two in step.
 */
final class PackageSignature
{
    public const CONTEXT = 'inventoros-marketplace-package-v1';

    private const VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$/';

    private const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    public static function message(string $slug, string $version, string $sha256): string
    {
        return self::CONTEXT."\n".$slug."\n".$version."\n".strtolower($sha256)."\n";
    }

    /**
     * A version string that can go into the signed statement unambiguously
     * (no separators, bounded length).
     */
    public static function isValidVersion(string $version): bool
    {
        return preg_match(self::VERSION_PATTERN, $version) === 1;
    }

    public static function isValidSha256(string $sha256): bool
    {
        return preg_match(self::SHA256_PATTERN, strtolower($sha256)) === 1;
    }
}
