<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Jobs\RefreshMarketplaceEntitlementsJob;
use App\Models\Auth\Organization;
use App\Models\System\MarketplaceEntitlement;
use App\Models\System\SystemSetting;
use App\Support\ReleaseSignatureVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runtime licences of paid plugins (plugin_licence()).
 *
 * The marketplace issues each organization a document listing the paid
 * plugins its connected inventoros.com account owns, each entry signed with
 * the marketplace Ed25519 key over (install id, slug, expires_at). The
 * document is stored per organization and verified against the configured
 * marketplace public key every time a licence is read, so editing the
 * stored row grants nothing.
 *
 * Reading a licence never contacts the marketplace: when the document is
 * older than the refresh interval, a queued job refreshes it (at most once
 * an hour per organization), and the scheduler refreshes every connected
 * organization daily. When the marketplace cannot be reached, the last
 * document keeps counting for the offline grace period after its expiry.
 *
 * Fail safe: nothing here throws to the caller or blocks a request; anything
 * that cannot be verified is reported as `unknown` and the plugin degrades
 * to read-only.
 */
final class PluginLicenceService
{
    private const INSTALLATION_SETTING = 'marketplace_installation_id';

    /** @var array<int, MarketplaceEntitlement|null> */
    private array $documents = [];

    public function __construct(private readonly MarketplaceClient $client) {}

    public function licence(string $slug, Organization|int|null $organization = null): PluginLicence
    {
        try {
            return $this->resolve($slug, $organization);
        } catch (\Throwable $e) {
            Log::warning('Plugin licence check failed', ['slug' => $slug, 'error' => $e->getMessage()]);

            return new PluginLicence($slug, PluginLicence::UNKNOWN, 'error');
        }
    }

    /**
     * This installation's id for an organization: a random id generated once
     * per installation, plus the organization id.
     */
    public function installId(int $organizationId): string
    {
        $installation = (string) SystemSetting::get(self::INSTALLATION_SETTING, '');

        if (! Str::isUuid($installation)) {
            $installation = (string) Str::uuid();
            SystemSetting::set(self::INSTALLATION_SETTING, $installation, 'string', 'Identifies this installation to the plugin marketplace');
        }

        return $installation.':'.$organizationId;
    }

    /**
     * Fetch and store the organization's entitlement document. Returns false
     * (and keeps the previous document) when the marketplace cannot be
     * reached or answers with something that does not verify.
     */
    public function refresh(Organization $organization): bool
    {
        $token = $organization->marketplace_token;

        if (! is_string($token) || $token === '') {
            $this->forget($organization);

            return false;
        }

        $installId = $this->installId((int) $organization->id);
        $row = MarketplaceEntitlement::withoutGlobalScopes()->firstOrNew(['organization_id' => $organization->id]);
        $row->install_id = $installId;
        $row->attempted_at = now();

        try {
            $document = $this->client->entitlements($token, $installId);
            $this->assertVerifies($document);
        } catch (\Throwable $e) {
            $row->last_error = Str::limit($e->getMessage(), 490);
            $row->save();
            unset($this->documents[$organization->id]);

            return false;
        }

        $row->document = (string) json_encode($document);
        $row->fetched_at = now();
        $row->last_error = null;
        $row->save();
        unset($this->documents[$organization->id]);

        return true;
    }

    /**
     * Drop an organization's document (on disconnect).
     */
    public function forget(Organization $organization): void
    {
        MarketplaceEntitlement::withoutGlobalScopes()->where('organization_id', $organization->id)->delete();
        unset($this->documents[$organization->id]);
    }

    public function graceDays(): int
    {
        return max(0, (int) config('marketplace.entitlement_grace_days', 14));
    }

    private function resolve(string $slug, Organization|int|null $organization): PluginLicence
    {
        $organization = $this->organization($organization);

        if ($organization === null) {
            return new PluginLicence($slug, PluginLicence::UNKNOWN, 'no_organization');
        }

        if (! MarketplaceInstaller::publicKeyConfigured()) {
            return new PluginLicence($slug, PluginLicence::UNKNOWN, 'no_public_key');
        }

        if (! is_string($organization->marketplace_token) || $organization->marketplace_token === '') {
            return new PluginLicence($slug, PluginLicence::MISSING, 'not_connected');
        }

        $row = $this->document((int) $organization->id);
        $this->refreshInBackgroundIfStale($organization, $row);

        if ($row === null || $row->document === null || $row->fetched_at === null) {
            return new PluginLicence($slug, PluginLicence::UNKNOWN, 'not_checked');
        }

        $checkedAt = CarbonImmutable::instance($row->fetched_at);
        $document = json_decode($row->document, true);
        $installId = $this->installId((int) $organization->id);

        if (! is_array($document) || ($document['install_id'] ?? null) !== $installId || $row->install_id !== $installId) {
            return new PluginLicence($slug, PluginLicence::UNKNOWN, 'install_mismatch', checkedAt: $checkedAt);
        }

        $entry = collect($document['entitlements'] ?? [])->first(fn ($entry) => is_array($entry) && ($entry['slug'] ?? null) === $slug);

        if ($entry === null) {
            return new PluginLicence($slug, PluginLicence::MISSING, 'not_owned', checkedAt: $checkedAt);
        }

        if (! $this->entryVerifies($installId, $entry)) {
            return new PluginLicence($slug, PluginLicence::UNKNOWN, 'invalid_signature', checkedAt: $checkedAt);
        }

        $expiresAt = CarbonImmutable::parse($entry['expires_at']);
        $graceUntil = $expiresAt->addDays($this->graceDays());
        $now = CarbonImmutable::now();

        if ($now->lt($expiresAt)) {
            return new PluginLicence($slug, PluginLicence::VALID, 'active', $expiresAt, $graceUntil, false, $checkedAt);
        }

        // Past expiry. The grace period covers an install that could not
        // reach the marketplace since before the expiry; when the marketplace
        // itself said it has expired (fetched after expires_at), it has.
        if ($checkedAt->lt($expiresAt) && $now->lt($graceUntil)) {
            return new PluginLicence($slug, PluginLicence::VALID, 'offline_grace', $expiresAt, $graceUntil, true, $checkedAt);
        }

        return new PluginLicence($slug, PluginLicence::EXPIRED, 'expired', $expiresAt, $graceUntil, false, $checkedAt);
    }

    private function organization(Organization|int|null $organization): ?Organization
    {
        if ($organization instanceof Organization) {
            return $organization;
        }

        if (is_int($organization)) {
            return Organization::find($organization);
        }

        $user = auth()->user();

        return $user?->organization_id ? Organization::find($user->organization_id) : null;
    }

    private function document(int $organizationId): ?MarketplaceEntitlement
    {
        if (! array_key_exists($organizationId, $this->documents)) {
            $this->documents[$organizationId] = MarketplaceEntitlement::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->first();
        }

        return $this->documents[$organizationId];
    }

    /**
     * Queue a refresh when the document is missing or older than the refresh
     * interval, at most once an hour per organization.
     */
    private function refreshInBackgroundIfStale(Organization $organization, ?MarketplaceEntitlement $row): void
    {
        $hours = max(1, (int) config('marketplace.entitlement_refresh_hours', 24));

        if ($row?->fetched_at !== null && $row->fetched_at->gt(now()->subHours($hours))) {
            return;
        }

        if (! Cache::add('plg:marketplace:entitlements:refresh:'.$organization->id, true, now()->addHour())) {
            return;
        }

        // With the sync queue the refresh would run inside this request;
        // run it after the response is sent instead.
        if (config('queue.default') === 'sync') {
            RefreshMarketplaceEntitlementsJob::dispatchAfterResponse((int) $organization->id);

            return;
        }

        RefreshMarketplaceEntitlementsJob::dispatch((int) $organization->id);
    }

    /**
     * A refreshed document must verify as a whole before it replaces the
     * stored one, so a broken or tampered answer never displaces a good one.
     *
     * @param  array{install_id: string, entitlements: array<int, array<string, string>>}  $document
     */
    private function assertVerifies(array $document): void
    {
        if (! MarketplaceInstaller::publicKeyConfigured()) {
            throw new MarketplaceException('No marketplace signing key is configured (INVENTOROS_MARKETPLACE_PUBLIC_KEY).');
        }

        foreach ($document['entitlements'] as $entry) {
            if (! $this->entryVerifies($document['install_id'], $entry)) {
                throw new MarketplaceException("The marketplace licence for {$entry['slug']} failed signature verification.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function entryVerifies(string $installId, array $entry): bool
    {
        if (! is_string($entry['slug'] ?? null) || ! is_string($entry['expires_at'] ?? null) || ! is_string($entry['signature'] ?? null)
            || ! EntitlementStatement::isValidExpiresAt($entry['expires_at'])) {
            return false;
        }

        try {
            ReleaseSignatureVerifier::verifyMessage(
                EntitlementStatement::message($installId, $entry['slug'], $entry['expires_at']),
                $entry['signature'],
                (string) config('marketplace.public_key'),
            );
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }
}
