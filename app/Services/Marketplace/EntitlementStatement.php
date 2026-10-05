<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

/**
 * What the marketplace signs for a runtime plugin entitlement.
 *
 * Each entitlement in GET /api/v1/marketplace/entitlements carries a
 * detached Ed25519 signature (base64) over this statement, one field per
 * line, each line ending in \n:
 *
 *     inventoros-marketplace-entitlement-v1
 *     <install id>
 *     <plugin slug>
 *     <expires_at, UTC, YYYY-MM-DDTHH:MM:SSZ>
 *
 * The install id names this installation and organization, so a document
 * copied to another install (or another organization on this one) does not
 * verify. The site (inventoros.com, App\Services\Marketplace\MarketplaceSigner)
 * builds exactly the same bytes; keep the two in step.
 */
final class EntitlementStatement
{
    public const CONTEXT = 'inventoros-marketplace-entitlement-v1';

    private const INSTALL_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9:._-]{0,127}$/';

    private const EXPIRES_AT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    public static function message(string $installId, string $slug, string $expiresAt): string
    {
        return self::CONTEXT."\n".$installId."\n".$slug."\n".$expiresAt."\n";
    }

    public static function isValidInstallId(string $installId): bool
    {
        return preg_match(self::INSTALL_ID_PATTERN, $installId) === 1;
    }

    public static function isValidExpiresAt(string $expiresAt): bool
    {
        return preg_match(self::EXPIRES_AT_PATTERN, $expiresAt) === 1;
    }
}
